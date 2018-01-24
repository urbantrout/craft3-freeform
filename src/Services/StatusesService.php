<?php
/**
 * Freeform for Craft
 *
 * @package       Solspace:Freeform
 * @author        Solspace, Inc.
 * @copyright     Copyright (c) 2008-2017, Solspace, Inc.
 * @link          https://solspace.com/craft/freeform
 * @license       https://solspace.com/software/license-agreement
 */

namespace Solspace\Freeform\Services;

use craft\db\Query;
use Solspace\Freeform\Events\Statuses\DeleteEvent;
use Solspace\Freeform\Events\Statuses\SaveEvent;
use Solspace\Freeform\Freeform;
use Solspace\Freeform\Library\Database\StatusHandlerInterface;
use Solspace\Freeform\Library\Helpers\PermissionsHelper;
use Solspace\Freeform\Models\StatusModel;
use Solspace\Freeform\Records\StatusRecord;
use yii\base\Component;

class StatusesService extends Component implements StatusHandlerInterface
{
    const EVENT_BEFORE_SAVE   = 'beforeSave';
    const EVENT_AFTER_SAVE    = 'afterSave';
    const EVENT_BEFORE_DELETE = 'beforeDelete';
    const EVENT_AFTER_DELETE  = 'afterDelete';

    /** @var StatusRecord[] */
    private static $statusCache;
    private static $allStatusesLoaded;

    /**
     * Get the ID of the default status
     *
     * @return int
     */
    public function getDefaultStatusId(): int
    {
        $id = (new Query())
            ->select(['id'])
            ->from(StatusRecord::TABLE)
            ->where('isDefault = 1')
            ->scalar();

        return (int) $id;
    }

    /**
     * @param bool $indexById
     *
     * @return StatusRecord[]
     */
    public function getAllStatuses($indexById = true): array
    {
        if (null === self::$statusCache || !self::$allStatusesLoaded) {
            self::$statusCache = [];

            $results = $this->getStatusQuery()->all();

            foreach ($results as $result) {
                $status = $this->createStatus($result);

                self::$statusCache[$status->id] = $status;
            }

            self::$allStatusesLoaded = true;
        }

        if (!$indexById) {
            return array_values(self::$statusCache);
        }

        return self::$statusCache;
    }

    /**
     * @param bool $indexById
     *
     * @return array
     */
    public function getAllStatusNames(bool $indexById = true): array
    {
        $list = [];
        foreach ($this->getAllStatuses() as $status) {
            if ($indexById) {
                $list[$status->id] = $status->name;
            } else {
                $list[] = $status->name;
            }
        }

        return $list;
    }

    /**
     * Returns an array of status ID's
     *
     * @return array
     */
    public function getAllStatusIds(): array
    {
        return (new Query())
            ->select(['id'])
            ->from(StatusRecord::TABLE)
            ->orderBy(['name' => SORT_ASC])
            ->column();
    }

    /**
     * @param int $id
     *
     * @return StatusModel|null
     */
    public function getStatusById($id)
    {
        if (null === self::$statusCache) {
            self::$statusCache = [];
        }

        if (null === self::$statusCache || !isset(self::$statusCache[$id])) {
            $result = $this->getStatusQuery()
                ->where(['id' => $id])
                ->one();

            $status = null;
            if ($result) {
                $status = $this->createStatus($result);
            }

            self::$statusCache[$id] = $status;
        }

        return self::$statusCache[$id];
    }

    /**
     * @param StatusModel $model
     *
     * @return bool
     * @throws \Exception
     */
    public function save(StatusModel $model): bool
    {
        $isNew = !$model->id;

        if (!$isNew) {
            $record = StatusRecord::findOne(['id' => $model->id]);
        } else {
            $record = StatusRecord::create();
        }

        $record->name      = $model->name;
        $record->handle    = $model->name;
        $record->isDefault = $model->isDefault;
        $record->color     = $model->color;
        $record->sortOrder = $model->sortOrder;

        $record->validate();
        $model->addErrors($record->getErrors());

        $beforeSaveEvent = new SaveEvent($model, $isNew);
        $this->trigger(self::EVENT_BEFORE_SAVE, $beforeSaveEvent);

        if ($beforeSaveEvent->isValid && !$model->hasErrors()) {

            $transaction = \Craft::$app->getDb()->beginTransaction();
            try {
                $record->save(false);

                self::$statusCache[$record->id] = $record;

                if ($transaction !== null) {
                    $transaction->commit();
                }

                // Force other default statuses to be turned off
                if ($record->isDefault) {
                    \Craft::$app
                        ->getDb()
                        ->createCommand()
                        ->update(
                            StatusRecord::TABLE,
                            ['isDefault' => 0],
                            'id != :id',
                            ['id' => $record->id]
                        )
                        ->execute();
                }

                $this->trigger(self::EVENT_AFTER_SAVE, new SaveEvent($model, $isNew));

                return true;
            } catch (\Exception $e) {
                if ($transaction !== null) {
                    $transaction->rollBack();
                }

                throw $e;
            }
        }

        return false;
    }

    /**
     * @param int $id
     *
     * @return bool
     * @throws \Exception
     */
    public function deleteById($id)
    {
        PermissionsHelper::requirePermission(PermissionsHelper::PERMISSION_SETTINGS_ACCESS);

        $model = $this->getStatusById($id);

        if (!$model) {
            return false;
        }

        $record = StatusRecord::findOne(['id' => $model->id]);
        if (!$record) {
            return false;
        }

        $beforeDeleteEvent = new DeleteEvent($model);
        $this->trigger(self::EVENT_BEFORE_DELETE, $beforeDeleteEvent);
        if (!$beforeDeleteEvent->isValid) {
            return false;
        }

        if ($record->isDefault) {
            return false;
        }

        $transaction = \Craft::$app->getDb()->beginTransaction();
        try {
            Freeform::getInstance()->submissions->swapStatuses($record->id, $this->getDefaultStatusId());

            $affectedRows = \Craft::$app
                ->getDb()
                ->createCommand()
                ->delete(StatusRecord::TABLE, ['id' => $record->id])
                ->execute();

            if ($transaction !== null) {
                $transaction->commit();
            }

            Freeform::getInstance()->forms->swapDeletedStatusToDefault($record->id, $this->getDefaultStatusId());

            $this->trigger(self::EVENT_AFTER_DELETE, new DeleteEvent($model));

            return (bool) $affectedRows;
        } catch (\Exception $exception) {
            if ($transaction !== null) {
                $transaction->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return int
     */
    public function getNextSortOrder(): int
    {
        $maxSortOrder = (new Query())
            ->select('MAX(sortOrder)')
            ->from(StatusRecord::TABLE)
            ->scalar();

        return (int) $maxSortOrder + 1;
    }

    /**
     * @param array $data
     *
     * @return StatusModel
     */
    private function createStatus(array $data): StatusModel
    {
        $status = new StatusModel($data);

        $status->isDefault = (bool) $status->isDefault;
        $status->id        = (int) $status->id;
        $status->sortOrder = (int) $status->sortOrder;

        return $status;
    }

    /**
     * @return Query
     */
    private function getStatusQuery(): Query
    {
        return (new Query())
            ->select(
                [
                    'statuses.id',
                    'statuses.name',
                    'statuses.handle',
                    'statuses.isDefault',
                    'statuses.color',
                    'statuses.sortOrder',
                ]
            )
            ->from(StatusRecord::TABLE . ' statuses')
            ->orderBy(['statuses.sortOrder' => SORT_ASC]);
    }
}