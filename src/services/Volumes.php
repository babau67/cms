<?php
namespace craft\services;

use Craft;
use craft\base\Volume;
use craft\base\VolumeInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\errors\MissingComponentException;
use craft\errors\VolumeException;
use craft\events\RegisterComponentTypesEvent;
use craft\events\VolumeEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\Component as ComponentHelper;
use craft\records\Volume as AssetVolumeRecord;
use craft\records\VolumeFolder;
use craft\volumes\Local;
use craft\volumes\MissingVolume;
use craft\volumes\Temp;
use yii\base\Component;

/**
 * Class AssetVolumesService
 *
 * @author     Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright  Copyright (c) 2014, Pixel & Tonic, Inc.
 * @license    http://craftcms.com/license Craft License Agreement
 * @see        http://craftcms.com
 * @package    craft.app.services
 * @since      3.0
 */
class Volumes extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event RegisterComponentTypesEvent The event that is triggered when registering volume types.
     */
    const EVENT_REGISTER_VOLUME_TYPES = 'registerVolumeTypes';

    /**
     * @event VolumeEvent The event that is triggered before an Asset volume is saved.
     */
    const EVENT_BEFORE_SAVE_VOLUME = 'beforeSaveVolume';

    /**
     * @event VolumeEvent The event that is triggered after an Asset volume is saved.
     */
    const EVENT_AFTER_SAVE_VOLUME = 'afterSaveVolume';

    /**
     * @event VolumeEvent The event that is triggered before an Asset volume is deleted.
     */
    const EVENT_BEFORE_DELETE_VOLUME = 'beforeDeleteVolume';

    /**
     * @event VolumeEvent The event that is triggered after a Asset volume is deleted.
     */
    const EVENT_AFTER_DELETE_VOLUME = 'afterDeleteVolume';

    // Properties
    // =========================================================================

    /**
     * @var
     */
    private $_allVolumeIds;

    /**
     * @var
     */
    private $_viewableVolumeIds;

    /**
     * @var
     */
    private $_viewableVolumes;

    /**
     * @var
     */
    private $_publicVolumeIds;

    /**
     * @var
     */
    private $_publicVolumes;

    /**
     * @var
     */
    private $_volumesById;

    /**
     * @var bool
     */
    private $_fetchedAllVolumes = false;

    // Public Methods
    // =========================================================================

    // Volumes
    // -------------------------------------------------------------------------

    /**
     * Returns all registered volume types.
     *
     * @return string[]
     */
    public function getAllVolumeTypes()
    {
        $volumeTypes = [
            Local::class
        ];

        $event = new RegisterComponentTypesEvent([
            'types' => $volumeTypes
        ]);
        $this->trigger(self::EVENT_REGISTER_VOLUME_TYPES, $event);

        return $event->types;
    }

    /**
     * Returns all of the volume IDs.
     *
     * @return array
     */
    public function getAllVolumeIds()
    {
        if ($this->_allVolumeIds !== null) {
            return $this->_allVolumeIds;
        }

        if ($this->_fetchedAllVolumes) {
            return $this->_allVolumeIds = array_keys($this->_volumesById);
        }

        return $this->_allVolumeIds = (new Query())
            ->select(['id'])
            ->from(['{{%volumes}}'])
            ->column();
    }

    /**
     * Returns all volume IDs that are viewable by the current user.
     *
     * @return array
     */
    public function getViewableVolumeIds()
    {
        if ($this->_viewableVolumeIds !== null) {
            return $this->_viewableVolumeIds;
        }

        $this->_viewableVolumeIds = [];

        foreach ($this->getAllVolumeIds() as $volumeId) {
            if (Craft::$app->user->checkPermission('viewVolume:'.$volumeId)) {
                $this->_viewableVolumeIds[] = $volumeId;
            }
        }

        return $this->_viewableVolumeIds;
    }

    /**
     * Returns all volumes that are viewable by the current user.
     *
     * @param string|null $indexBy
     *
     * @return VolumeInterface[]
     */
    public function getViewableVolumes($indexBy = null)
    {
        if ($this->_viewableVolumes !== null) {
            return $indexBy ? ArrayHelper::index($this->_viewableVolumes, $indexBy) : $this->_viewableVolumes;
        }

        $this->_viewableVolumes = [];

        foreach ($this->getAllVolumes() as $volume) {
            if (Craft::$app->user->checkPermission('viewVolume:'.$volume->id)) {
                $this->_viewableVolumes[] = $volume;
            }
        }

        return $indexBy ? ArrayHelper::index($this->_viewableVolumes, $indexBy) : $this->_viewableVolumes;
    }

    /**
     * Returns all volume IDs that have public URLs.
     *
     * @return integer[]
     */
    public function getPublicVolumeIds()
    {
        if ($this->_publicVolumeIds !== null) {
            return $this->_publicVolumeIds;
        }

        $this->_publicVolumeIds = [];

        foreach ($this->getAllVolumes() as $volume) {
            /** @var Volume $volume */
            if ($volume->hasUrls) {
                $this->_publicVolumeIds[] = $volume->id;
            }
        }

        return $this->_publicVolumeIds;
    }

    /**
     * Returns all volumes that have public URLs.
     *
     * @param string|null $indexBy
     *
     * @return VolumeInterface[]
     */
    public function getPublicVolumes($indexBy = null)
    {
        if ($this->_publicVolumes !== null) {
            return $indexBy ? ArrayHelper::index($this->_publicVolumes, $indexBy) : $this->_publicVolumes;
        }

        $this->_publicVolumes = [];

        foreach ($this->getAllVolumes() as $volume) {
            /** @var Volume $volume */
            if ($volume->hasUrls) {
                $this->_publicVolumes[] = $volume;
            }
        }

        return $indexBy ? ArrayHelper::index($this->_publicVolumes, $indexBy) : $this->_publicVolumes;
    }

    /**
     * Returns the total number of volumes.
     *
     * @return integer
     */
    public function getTotalVolumes()
    {
        return count($this->getAllVolumeIds());
    }

    /**
     * Returns the total number of volumes that are viewable by the current user.
     *
     * @return integer
     */
    public function getTotalViewableVolumes()
    {
        return count($this->getViewableVolumeIds());
    }

    /**
     * Returns all volumes.
     *
     * @param string|null $indexBy
     *
     * @return VolumeInterface[]
     */
    public function getAllVolumes($indexBy = null)
    {
        if ($this->_fetchedAllVolumes) {
            if ($indexBy === 'id') {
                return $this->_volumesById;
            }

            return $indexBy ? ArrayHelper::index($this->_volumesById, $indexBy) : array_values($this->_volumesById);
        }

        $this->_volumesById = [];
        $results = $this->_createVolumeQuery()
            ->all();

        foreach ($results as $result) {
            /** @var Volume $volume */
            $volume = $this->createVolume($result);
            $this->_volumesById[$volume->id] = $volume;
        }

        $this->_fetchedAllVolumes = true;

        if ($indexBy === 'id') {
            return $this->_volumesById;
        }

        return $indexBy ? ArrayHelper::index($this->_volumesById, $indexBy) : array_values($this->_volumesById);
    }

    /**
     * Returns a volume by its ID.
     *
     * @param integer|null $volumeId
     *
     * @return VolumeInterface|null
     */
    public function getVolumeById($volumeId)
    {
        // TODO: Temp volumes should not be created here!
        // Temporary volume?
        if ($volumeId === null) {
            return new Temp();
        }

        if ($this->_volumesById !== null && array_key_exists($volumeId, $this->_volumesById)) {
            return $this->_volumesById[$volumeId];
        }

        if ($this->_fetchedAllVolumes) {
            return null;
        }

        $result = $this->_createVolumeQuery()
            ->where(['id' => $volumeId])
            ->one();

        if (!$result) {
            return $this->_volumesById[$volumeId] = null;
        }

        return $this->_volumesById[$volumeId] = $this->createVolume($result);
    }

    /**
     * Saves an asset volume.
     *
     * @param VolumeInterface $volume        the volume to be saved.
     * @param boolean         $runValidation Whether the volume should be validated
     *
     * @return boolean Whether the field was saved successfully
     * @throws \Exception
     */

    public function saveVolume(VolumeInterface $volume, $runValidation = true)
    {
        /** @var Volume $volume */
        if ($runValidation && !$volume->validate()) {
            Craft::info('Volume not saved due to validation error.', __METHOD__);

            return false;
        }

        $isNewVolume = $volume->getIsNew();

        // Fire a 'beforeSaveVolume' event
        $this->trigger(self::EVENT_BEFORE_SAVE_VOLUME, new VolumeEvent([
            'volume' => $volume,
            'isNew' => $isNewVolume
        ]));

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            if (!$volume->beforeSave($isNewVolume)) {
                $transaction->rollBack();

                return false;
            }

            $volumeRecord = $this->_getVolumeRecordById($volume->id);

            $volumeRecord->name = $volume->name;
            $volumeRecord->handle = $volume->handle;
            $volumeRecord->type = get_class($volume);
            $volumeRecord->hasUrls = $volume->hasUrls;
            $volumeRecord->settings = $volume->getSettings();
            $volumeRecord->fieldLayoutId = $volume->fieldLayoutId;

            if ($volume->hasUrls) {
                $volumeRecord->url = $volume->url;
            } else {
                $volumeRecord->url = null;
            }

            $fields = Craft::$app->getFields();

            if (!$isNewVolume) {
                $fields->deleteLayoutById($volumeRecord->fieldLayoutId);
            } else {
                // Set the sort order
                $maxSortOrder = (new Query())
                    ->from(['{{%volumes}}'])
                    ->max('[[sortOrder]]');

                $volumeRecord->sortOrder = $maxSortOrder + 1;
            }

            // Save the new one
            $fieldLayout = $volume->getFieldLayout();
            $fields->saveLayout($fieldLayout);

            // Update the volume record/model with the new layout ID
            $volume->fieldLayoutId = $fieldLayout->id;
            $volumeRecord->fieldLayoutId = $fieldLayout->id;

            // Save the volume
            $volumeRecord->save(false);

            if ($isNewVolume) {
                // Now that we have a volume ID, save it on the model
                $volume->id = $volumeRecord->id;
            } else {
                // Update the top folder's name with the volume's new name
                $assets = Craft::$app->getAssets();
                $topFolder = $assets->findFolder([
                    'volumeId' => $volume->id,
                    'parentId' => ':empty:'
                ]);

                if ($topFolder !== null && $topFolder->name != $volume->name) {
                    $topFolder->name = $volume->name;
                    $assets->storeFolderRecord($topFolder);
                }
            }

            $this->ensureTopFolder($volume);

            $volume->afterSave($isNewVolume);

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();

            throw $e;
        }

        if ($isNewVolume && $this->_fetchedAllVolumes) {
            $this->_volumesById[$volume->id] = $volume;
        }

        if ($this->_viewableVolumeIds !== null && Craft::$app->user->checkPermission('viewVolume:'.$volume->id)) {
            $this->_viewableVolumeIds[] = $volume->id;
        }

        // Fire an 'afterSaveVolume' event
        $this->trigger(self::EVENT_AFTER_SAVE_VOLUME, new VolumeEvent([
            'volume' => $volume,
            'isNew' => $isNewVolume
        ]));

        return true;
    }

    /**
     * Reorders asset volumes.
     *
     * @param array $volumeIds
     *
     * @throws \Exception
     * @return boolean
     */
    public function reorderVolumes($volumeIds)
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($volumeIds as $volumeOrder => $volumeId) {
                $volumeRecord = $this->_getVolumeRecordById($volumeId);
                $volumeRecord->sortOrder = $volumeOrder + 1;
                $volumeRecord->save();
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * Creates an asset volume with a given config.
     *
     * @param mixed $config The asset volume’s class name, or its config, with a `type` value and optionally a `settings` value
     *
     * @return VolumeInterface The asset volume
     */
    public function createVolume($config)
    {
        if (is_string($config)) {
            $config = ['type' => $config];
        }

        try {
            /** @var Volume $volume */
            $volume = ComponentHelper::createComponent($config, VolumeInterface::class);
        } catch (MissingComponentException $e) {
            $config['errorMessage'] = $e->getMessage();
            $config['expectedType'] = $config['type'];
            unset($config['type']);

            $volume = new MissingVolume($config);
        }

        return $volume;
    }

    /**
     * Ensures a top level folder exists that matches the model.
     *
     * @param VolumeInterface $volume
     *
     * @return integer
     */
    public function ensureTopFolder(VolumeInterface $volume)
    {
        /** @var Volume $volume */
        $folder = VolumeFolder::findOne(
            [
                'name' => $volume->name,
                'volumeId' => $volume->id
            ]
        );

        if (empty($folder)) {
            $folder = new VolumeFolder();
            $folder->volumeId = $volume->id;
            $folder->parentId = null;
            $folder->name = $volume->name;
            $folder->path = '';
            $folder->save();
        }

        return $folder->id;
    }

    /**
     * Deletes an asset volume by its ID.
     *
     * @param integer $volumeId
     *
     * @throws \Exception
     * @return boolean
     */
    public function deleteVolumeById($volumeId)
    {
        $volume = $this->getVolumeById($volumeId);

        if (!$volume) {
            return false;
        }

        return $this->deleteVolume($volume);
    }

    /**
     * Deletes an asset volume.
     *
     * @param VolumeInterface $volume The volume to delete
     *
     * @throws \Exception
     * @return boolean
     */
    public function deleteVolume($volume)
    {
        // Fire a 'beforeDeleteVolume' event
        $this->trigger(self::EVENT_BEFORE_DELETE_VOLUME, new VolumeEvent([
            'volume' => $volume
        ]));

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            if (!$volume->beforeDelete()) {
                $transaction->rollBack();

                return false;
            }

            // Delete the assets
            $assets = Asset::find()
                ->status(null)
                ->enabledForSite(false)
                ->volumeId($volume->id)
                ->all();

            foreach ($assets as $asset) {
                Craft::$app->getElements()->deleteElement($asset);
            }

            // Nuke the asset volume.
            $db->createCommand()
                ->delete('{{%volumes}}', ['id' => $volume->id])
                ->execute();

            $volume->afterDelete();

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();

            throw $e;
        }

        // Fire an 'afterDeleteVolume' event
        $this->trigger(self::EVENT_AFTER_DELETE_VOLUME, new VolumeEvent([
            'volume' => $volume
        ]));

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a DbCommand object prepped for retrieving volumes.
     *
     * @return Query
     */
    private function _createVolumeQuery()
    {
        return (new Query())
            ->select([
                'id',
                'dateCreated',
                'dateUpdated',
                'name',
                'handle',
                'hasUrls',
                'url',
                'sortOrder',
                'fieldLayoutId',
                'type',
                'settings',
            ])
            ->from(['{{%volumes}}'])
            ->orderBy(['sortOrder' => SORT_ASC]);
    }

    /**
     * Gets a volume's record.
     *
     * @param integer $volumeId
     *
     * @throws VolumeException If the volume does not exist.
     * @return AssetVolumeRecord
     */
    private function _getVolumeRecordById($volumeId = null)
    {
        if ($volumeId) {
            $volumeRecord = AssetVolumeRecord::findOne(['id' => $volumeId]);

            if (!$volumeRecord) {
                throw new VolumeException(Craft::t('No volume exists with the ID “{id}”.',
                    ['id' => $volumeId]));
            }
        } else {
            $volumeRecord = new AssetVolumeRecord();
        }

        return $volumeRecord;
    }
}
