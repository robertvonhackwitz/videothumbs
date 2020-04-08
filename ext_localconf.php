<?php
defined('TYPO3_MODE') || die();

call_user_func(
    function()
    {
        
        $settings = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['videothumbs']);
        
        if($settings['localMediaThumbsEnable'] == 1) {
            /** @var \TYPO3\CMS\Extbase\SignalSlot\Dispatcher $signalSlotDispatcher */
            $signalSlotDispatcher = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher::class);
            $signalSlotDispatcher->connect(
                TYPO3\CMS\Core\Resource\ResourceStorage::class,
                \TYPO3\CMS\Core\Resource\Service\FileProcessingService::SIGNAL_PreFileProcess,
                \RVH\Videothumbs\Slots\PreviewProcessingSlot::class,
                'processFile'
                );
        }
        unset($settings);
    }
);


