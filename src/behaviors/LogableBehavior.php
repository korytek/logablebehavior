<?php

namespace common\components;

use Yii;
use yii\db\BaseActiveRecord;
use yii\db\ActiveRecord;
use yii\base\Behavior;
use yii\base\ModelEvent;
use yii\db\StaleObjectException;

/**
 * LogableBehavior automatically fills the specified attributes with the current user ID and/or timestamp.
 *
 * To use LogableBehavior, insert the following code to your ActiveRecord class:
 *
 * ```php
 * use yii\behaviors\LogableBehavior;
 *
 * public function behaviors()
 * {
 *     return [
 *         LogableBehavior::className(),
 *     ];
 * }
 * ```
 *
 * By default, LogableBehavior will fill the `created_by` and `updated_by` attributes with the current user ID
 * and `creator_at` and `updater_at` with the current timestamp values
 * when the associated AR object is being inserted; it will fill the `updated_by` attribute
 * with the current user ID and `updater_at` attribute with current timestamp when the AR object is being updated;
 * it will fill the `deleted_by` and `deleter_at` attribute when AR object is being deleted with softDelete method.
 *
 * Because attribute values will be set automatically by this behavior, they are usually not user input and should therefore
 * not be validated, i.e. `created_by`, `updated_by` and `deleted_by` should not appear in the [[\yii\base\Model::rules()|rules()]] method
 * of the model.
 * For the above implementation to work with MySQL database, please declare the columns(`created_at`, `updated_at` and `deleted_at`)
 * as int(11) for being UNIX timestamp.
 *
 * If your attribute names are different, you may configure the [[createdAtAttribute]], [[updatedAtAttribute]], [[deletedAtAttribute]],
 * [[createdByAttribute]], [[updatedByAttribute]] and [[deletedByAttribute]] properties like the following:
 *
 * ```php
 * public function behaviors()
 * {
 *     return [
 *         [
 *             'class' => LogableBehavior::className(),
 *             'createdAtAttribute' => ['name' => 'created_at', 'type' => LogableBehavior::TYPE_TIMESTAMP],
 *             'updatedAtAttribute' => ['name' => 'updated_at', 'type' => LogableBehavior::TYPE_TIMESTAMP],
 *             'deletedAtAttribute' => ['name' => 'deleted_at', 'type' => LogableBehavior::TYPE_TIMESTAMP],
 *             'createdByAttribute' => ['name' => 'creator_id', 'type' => LogableBehavior::TYPE_USER_ID],
 *             'updatedByAttribute' => ['name' =? 'updater_id', 'type' => LogableBehavior::TYPE_USER_ID],
 *             'deletedByAttribute' => ['name' => 'deleter_id', 'type' => LogableBehavior::TYPE_USER_ID],
 *         ],
 *     ];
 * }
 * ```
 * @author Marcin Korytkowski <marcin@datait.pl>
 * @author Luciano Baraglia <luciano.baraglia@gmail.com>
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Alexander Kochetov <creocoder@gmail.com>
 * @since 2.0
 */
class LogableBehavior extends Behavior
{
    /**
     * @event ModelEvent an event that is triggered before deleting a record.
     * You may set [[ModelEvent::isValid]] to be false to stop the deletion.
     */
    const EVENT_BEFORE_SOFT_DELETE = 'beforeSoftDelete';
    /**
     * @event Event an event that is triggered after a record is deleted.
     */
    const EVENT_AFTER_SOFT_DELETE = 'afterSoftDelete';

    const TYPE_TIMESTAMP = 'timestamp';
    const TYPE_USER_ID = 'userId';
    /**
     * @var array key 'name' has attribute name that will receive value, key 'type' has attribute type
     * Set this property to false if you do not want to record the value.
     */
    public $createdAtAttribute = ['name' => 'created_at', 'type' => self::TYPE_TIMESTAMP];
    /**
     * @var array key 'name' has attribute name that will receive value, key 'type' has attribute type
     * Set this property to false if you do not want to record the value.
     */
    public $updatedAtAttribute = ['name' => 'updated_at', 'type' => self::TYPE_TIMESTAMP];
    /**
     * @var array key 'name' has attribute name that will receive value, key 'type' has attribute type
     * Set this property to false if you do not want to record the value.
     */
    public $deletedAtAttribute = ['name' => 'deleted_at', 'type' => self::TYPE_TIMESTAMP];
    /**
     * @var array key 'name' has attribute name that will receive value, key 'type' has attribute type
     * Set this property to false if you do not want to record the value.
     */
    public $createdByAttribute = ['name' => 'creator_id', 'type' => self::TYPE_USER_ID];
    /**
     * @var array key 'name' has attribute name that will receive value, key 'type' has attribute type
     * Set this property to false if you do not want to record the value.
     */
    public $updatedByAttribute = ['name' => 'updater_id', 'type' => self::TYPE_USER_ID];
    /**
     * @var array key 'name' has attribute name that will receive value, key 'type' has attribute type
     * Set this property to false if you do not want to record the value.
     */
    public $deletedByAttribute = ['name' => 'deleter_id', 'type' => self::TYPE_USER_ID];
    /**
     * @var array list of attributes that are to be automatically filled with the value specified via [[value]].
     * The array keys are the ActiveRecord events upon which the attributes are to be updated,
     * and the array values are the corresponding attribute(s) to be updated. You can use a string to represent
     * a single attribute, or an array to represent a list of attributes. For example,
     *
     * ```php
     * [
     *     ActiveRecord::EVENT_BEFORE_INSERT => ['attribute1', 'attribute2'],
     *     ActiveRecord::EVENT_BEFORE_UPDATE => 'attribute2',
     * ]
     * ```
     */
    public $attributes = [];
    /**
     * @var bool whether to skip this behavior when the `$owner` has not been
     * modified
     * @since 2.0.8
     */
    public $skipUpdateOnClean = true;
    /**
     * @var bool whether to preserve non-empty attribute values.
     * @since 2.0.13
     */
    public $preserveNonEmptyValues = false;
    /**
     * {@inheritdoc}
     *
     * In case, when the property is `null`, the value
     * of the result of the PHP function [time()](http://php.net/manual/en/function.time.php) will be used as the value.
     */
    public $timestampValue;
    /**
     * {@inheritdoc}
     *
     * In case, when the property is `null`, the
     * value of `Yii::$app->user->id` will be used as the value.
     */
    public $idValue;
        /**
     * @var bool whether to perform soft delete instead of regular delete.
     * If enabled [[BaseActiveRecord::delete()]] will perform soft deletion instead of actual record deleting.
     */

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return array_fill_keys(
            array_keys($this->attributes),
            'evaluateAttributes'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        if (empty($this->attributes)) {
            $this->attributes = [
                BaseActiveRecord::EVENT_BEFORE_INSERT => [$this->createdAtAttribute, $this->updatedAtAttribute, $this->createdByAttribute, $this->updatedByAttribute],
                BaseActiveRecord::EVENT_BEFORE_UPDATE => [$this->updatedAtAttribute, $this->updatedByAttribute],
                self::EVENT_BEFORE_SOFT_DELETE => [$this->deletedAtAttribute, $this->deletedByAttribute],
            ];
        }
    }

    /**
     * {@inheritdoc}
     *
     * In case, when the [[timestampValue]] is `null`, the result of the PHP function [time()](http://php.net/manual/en/function.time.php)
     * will be used as value.
     */
    protected function getTimestampValue($event)
    {
        if ($this->timestampValue === null) {
            return time();
        }

        if ($this->timestampValue instanceof Closure || (is_array($this->timestampValue) && is_callable($this->timestampValue))) {
            return call_user_func($this->timestampValue, $event);
        }

        return $this->timestampValue;
    }

    /**
     * {@inheritdoc}
     *
     * In case, when the [[value]] property is `null`, the value of [[defaultIdValue]] will be used as the value.
     */
    protected function getIdValue($event)
    {
        if ($this->idValue === null && Yii::$app->has('user')) {
            return Yii::$app->get('user')->id;
        }

        if ($this->idValue instanceof Closure || (is_array($this->idValue) && is_callable($this->idValue))) {
            return call_user_func($this->idValue, $event);
        }

        return $this->idValue;
    }

    /**
     * Evaluates the attribute value and assigns it to the current attributes.
     * @param Event $event
     */
    public function evaluateAttributes($event)
    {
        if ($this->skipUpdateOnClean
            && $event->name == ActiveRecord::EVENT_BEFORE_UPDATE
            && empty($this->owner->dirtyAttributes)
        ) {
            return;
        }

        if (!empty($this->attributes[$event->name])) {
            $attributes = (array) $this->attributes[$event->name];
            $idValue = $this->getIdValue($event);
            $timestampValue = $this->getTimestampValue($event);
            foreach ($attributes as $attribute) {
                // ignore attribute names which are not string (e.g. when set by TimestampBehavior::updatedAtAttribute)
                $attributeName = $attribute['name'];
                if (is_string($attributeName)) {
                    if ($this->preserveNonEmptyValues && !empty($this->owner->$attributeName)) {
                        continue;
                    }

                    switch ($attribute['type']) {
                        case self::TYPE_TIMESTAMP:
                            $this->owner->$attributeName = $timestampValue;

                            break;

                        case self::TYPE_USER_ID:
                            $this->owner->$attributeName = $idValue;

                            break;
                    }
                }
            }
        }
    }

    /**
     * Marks the owner as deleted.
     * @return int|false the number of rows marked as deleted, or false if the soft deletion is unsuccessful for some reason.
     * Note that it is possible the number of rows deleted is 0, even though the soft deletion execution is successful.
     * @throws StaleObjectException if optimistic locking is enabled and the data being updated is outdated.
     * @throws \Throwable in case soft delete failed in transactional mode.
     */
    public function softDelete()
    {
        $softDeleteCallback = function () {
            if (!$this->owner->beforeDelete()) {
                return false;
            }
            $result = $this->softDeleteInternal();
            $this->owner->afterDelete();

            return $result;
        };
        if (!$this->isTransactional(ActiveRecord::OP_DELETE) && !$this->isTransactional(ActiveRecord::OP_UPDATE)) {
            return call_user_func($softDeleteCallback);
        }
        $transaction = $this->beginTransaction();
        try {
            $result = call_user_func($softDeleteCallback);
            if ($result === false) {
                $transaction->rollBack();
            } else {
                $transaction->commit();
            }
            return $result;
        } catch (\Exception $exception) {
            // PHP < 7.0
        } catch (\Throwable $exception) {
            // PHP >= 7.0
        }
        $transaction->rollBack();
        throw $exception;
    }

    /**
     * Marks the owner as deleted.
     * @return int|false the number of rows marked as deleted, or false if the soft deletion is unsuccessful for some reason.
     * @throws StaleObjectException if optimistic locking is enabled and the data being updated is outdated.
     */
    protected function softDeleteInternal()
    {
        $result = false;
        if ($this->beforeSoftDelete()) {
            $attributes = $this->owner->getDirtyAttributes();
            $result = $this->updateAttributes($attributes);
            $this->afterSoftDelete();
        }
        return $result;
    }

    /**
     * This method is invoked before soft deleting a record.
     * The default implementation raises the [[EVENT_BEFORE_SOFT_DELETE]] event.
     * @return bool whether the record should be deleted. Defaults to true.
     */
    public function beforeSoftDelete()
    {
        if (method_exists($this->owner, 'beforeSoftDelete')) {
            if (!$this->owner->beforeSoftDelete()) {
                return false;
            }
        }
        $event = new ModelEvent();
        $this->owner->trigger(self::EVENT_BEFORE_SOFT_DELETE, $event);
        return $event->isValid;
    }

    /**
     * This method is invoked after soft deleting a record.
     * The default implementation raises the [[EVENT_AFTER_SOFT_DELETE]] event.
     * You may override this method to do postprocessing after the record is deleted.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    public function afterSoftDelete()
    {
        if (method_exists($this->owner, 'afterSoftDelete')) {
            $this->owner->afterSoftDelete();
        }
        $this->owner->trigger(self::EVENT_AFTER_SOFT_DELETE);
    }

    /**
     * Returns a value indicating whether the specified operation is transactional in the current owner scenario.
     * @return bool whether the specified operation is transactional in the current owner scenario.
     * @since 1.0.2
     */
    private function isTransactional($operation)
    {
        if (!$this->owner->hasMethod('isTransactional')) {
            return false;
        }
        return $this->owner->isTransactional($operation);
    }

    /**
     * Begins new database transaction if owner allows it.
     * @return \yii\db\Transaction|null transaction instance or `null` if not available.
     */
    private function beginTransaction()
    {
        $db = $this->owner->getDb();
        if ($db->hasMethod('beginTransaction')) {
            return $db->beginTransaction();
        }
        return null;
    }

    /**
     * Updates owner attributes taking [[BaseActiveRecord::optimisticLock()]] into account.
     * @param array $attributes the owner attributes (names or name-value pairs) to be updated
     * @return int the number of rows affected.
     * @throws StaleObjectException if optimistic locking is enabled and the data being updated is outdated.
     * @since 1.0.2
     */
    private function updateAttributes(array $attributes)
    {
        $owner = $this->owner;
        $lock = $owner->optimisticLock();
        if ($lock === null) {
            return $owner->updateAttributes($attributes);
        }
        $condition = $owner->getOldPrimaryKey(true);
        $attributes[$lock] = $owner->{$lock} + 1;
        $condition[$lock] = $owner->{$lock};
        $rows = $owner->updateAll($attributes, $condition);
        if (!$rows) {
            throw new StaleObjectException('The object being updated is outdated.');
        }
        foreach ($attributes as $name => $value) {
            $owner->{$name} = $value;
            $owner->setOldAttribute($name, $value);
        }
        return $rows;
    }

    /**
     * Handles owner 'beforeDelete' owner event, applying soft delete and preventing actual deleting.
     * @param ModelEvent $event event instance.
     */
    public function beforeDelete($event)
    {
        $this->softDeleteInternal();
        $event->isValid = false;
    }
}
