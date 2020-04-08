<?php
namespace RVH\Videothumbs\Slots;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Imaging\ImageMagickFile;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\Processing\LocalImageProcessor;
use TYPO3\CMS\Core\Resource\Service\FileProcessingService;
use TYPO3\CMS\Core\Type\File\ImageInfo;
use TYPO3\CMS\Core\Utility\CommandUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;

class PreviewProcessingSlot 
{
    /**
     * @var LocalImageProcessor
     */
    protected $processor;
    
    
    /**
     * @var string
     */
    protected $videoFileExt = '';
    
    /**
     * @var string
     */
    protected $videoFileProcessor = '';
    
    /**
     * @var string
     */
    protected $videoFileProcessorPath = '';
    
    
    public function __construct()
    {
        $extSettings = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['videothumbs']);
        $this->videoFileExt = $extSettings['localMediaContainers'];
        $this->videoFileProcessor = $extSettings['localMediaProcessor'];
        $this->videoFileProcessorPath = $extSettings['localMediaProcessorPath'];
    }
    
    /**
     * @param ProcessedFile $processedFile
     * @return bool
     */
    protected function needsReprocessing($processedFile)
    {
        return $processedFile->isNew()
        || (!$processedFile->usesOriginalFile() && !$processedFile->exists())
        || $processedFile->isOutdated();
    }
    
    /**
     * Process file
     * 
     *
     * @param FileProcessingService $fileProcessingService
     * @param DriverInterface $driver
     * @param ProcessedFile $processedFile
     * @param File $file
     * @param string $taskType
     * @param array $configuration
     */
    public function processFile(FileProcessingService $fileProcessingService, DriverInterface $driver, ProcessedFile $processedFile, File $file, $taskType, array $configuration)
    {

        // Processing video files only
        if($file->getType() !== File::FILETYPE_VIDEO && !GeneralUtility::inList($this->videoFileExt, $file->getExtension()))
        {
            return;
        }
        if ($taskType !== ProcessedFile::CONTEXT_IMAGEPREVIEW && $taskType !== ProcessedFile::CONTEXT_IMAGECROPSCALEMASK) {
            return;
        }
        // Check if processing is needed
        if (!$this->needsReprocessing($processedFile)) {
            return;
        }

        $temporaryFileName =  uniqid(Environment::getVarPath() . '/transient/videopreview_' . $file->getHashedIdentifier()) . '.jpg';

        if (!file_exists($temporaryFileName)) {

            $previewTemporaryFileName = $file->getForLocalProcessing(false);
            
            $isExt = Environment::isWindows() ? '.exe' : '';
            $path = $this->videoFileProcessorPath . $this->videoFileProcessor . $isExt;
                        
            $parameters = ' -ss 00:00:01 -i ' . CommandUtility::escapeShellArgument($previewTemporaryFileName)
                        . ' -frames:v 1 ' . CommandUtility::escapeShellArgument($temporaryFileName);

            $cmd = $path . $parameters . ' 2>&1';
            CommandUtility::exec($cmd);
        }

        $temporaryFileNameForResizedThumb = uniqid(Environment::getVarPath() . '/transient/video_' . $file->getHashedIdentifier()) . '.jpg';
        $configuration = $processedFile->getProcessingConfiguration();
        switch ($taskType) {
            case ProcessedFile::CONTEXT_IMAGEPREVIEW:
                $this->resizeImage($temporaryFileName, $temporaryFileNameForResizedThumb, $configuration);
                break;
                
            case ProcessedFile::CONTEXT_IMAGECROPSCALEMASK:
                $this->cropScaleImage($temporaryFileName, $temporaryFileNameForResizedThumb, $configuration);
                break;
        }
        GeneralUtility::unlink_tempfile($temporaryFileName);
        if (is_file($temporaryFileNameForResizedThumb)) {
            $processedFile->setName($this->getTargetFileName($processedFile));
            $imageInfo = GeneralUtility::makeInstance(ImageInfo::class, $temporaryFileNameForResizedThumb);
            $processedFile->updateProperties(
                [
                    'width' => $imageInfo->getWidth(),
                    'height' => $imageInfo->getHeight(),
                    'size' => filesize($temporaryFileNameForResizedThumb),
                    'checksum' => $processedFile->getTask()->getConfigurationChecksum()
                ]
                );
            $processedFile->updateWithLocalFile($temporaryFileNameForResizedThumb);
            GeneralUtility::unlink_tempfile($temporaryFileNameForResizedThumb);
            
            /** @var ProcessedFileRepository $processedFileRepository */
            $processedFileRepository = GeneralUtility::makeInstance(ProcessedFileRepository::class);
            $processedFileRepository->add($processedFile);
        }
    }
    
    /**
     * @param ProcessedFile $processedFile
     * @param string $prefix
     * @return string
     */
    protected function getTargetFileName(ProcessedFile $processedFile, $prefix = 'preview_')
    {
        return $prefix . $processedFile->getTask()->getConfigurationChecksum() . '_' . $processedFile->getOriginalFile()->getNameWithoutExtension() . '.jpg';
    }
    
    /**
     * @param string $originalFileName
     * @param string $temporaryFileName
     * @param array $configuration
     */
    protected function resizeImage($originalFileName, $temporaryFileName, $configuration)
    {
        // Create the temporary file
        if (empty($GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'])) {
            return;
        }
        
        if (file_exists($originalFileName)) {
            $arguments = CommandUtility::escapeShellArguments([
                'width' => $configuration['width'],
                'height' => $configuration['height'],
            ]);
            $parameters = '-sample ' . $arguments['width'] . 'x' . $arguments['height']
            . ' ' . ImageMagickFile::fromFilePath($originalFileName, 0)
            . ' ' . CommandUtility::escapeShellArgument($temporaryFileName);
            
            $cmd = CommandUtility::imageMagickCommand('convert', $parameters) . ' 2>&1';
            CommandUtility::exec($cmd);
        }
        
        if (!file_exists($temporaryFileName)) {
            // Create a error image
            $graphicalFunctions = $this->getGraphicalFunctionsObject();
            $graphicalFunctions->getTemporaryImageWithText($temporaryFileName, 'No thumb', 'generated!', PathUtility::basename($originalFileName));
        }
    }
    
    /**
     * cropScaleImage
     *
     * @param string $originalFileName
     * @param string $temporaryFileName
     * @param array $configuration
     */
    protected function cropScaleImage($originalFileName, $temporaryFileName, $configuration)
    {
        if (file_exists($originalFileName)) {
            $gifBuilder = GeneralUtility::makeInstance(GifBuilder::class);
            
            $options = $this->getConfigurationForImageCropScaleMask($configuration, $gifBuilder);
            $info = $gifBuilder->getImageDimensions($originalFileName);
            $data = $gifBuilder->getImageScale($info, $configuration['width'], $configuration['height'], $options);
            
            $info[0] = $data[0];
            $info[1] = $data[1];
            $frame = '';
            $params = $gifBuilder->cmds['jpg'];
            
            // Cropscaling:
            if ($data['crs']) {
                if (!$data['origW']) {
                    $data['origW'] = $data[0];
                }
                if (!$data['origH']) {
                    $data['origH'] = $data[1];
                }
                $offsetX = (int)(($data[0] - $data['origW']) * ($data['cropH'] + 100) / 200);
                $offsetY = (int)(($data[1] - $data['origH']) * ($data['cropV'] + 100) / 200);
                $params .= ' -crop ' . $data['origW'] . 'x' . $data['origH'] . '+' . $offsetX . '+' . $offsetY . '! ';
            }
            $command = $gifBuilder->scalecmd . ' ' . $info[0] . 'x' . $info[1] . '! ' . $params . ' ';
            $gifBuilder->imageMagickExec($originalFileName, $temporaryFileName, $command, $frame);
        }
        if (!file_exists($temporaryFileName)) {
            // Create a error image
            $graphicalFunctions = $this->getGraphicalFunctionsObject();
            $graphicalFunctions->getTemporaryImageWithText($temporaryFileName, 'No thumb', 'generated!', PathUtility::basename($originalFileName));
        }
    }
    
    /**
     * Get configuration for ImageCropScaleMask processing
     *
     * @param array $configuration
     * @param GifBuilder $gifBuilder
     * @return array
     */
    protected function getConfigurationForImageCropScaleMask(array $configuration, GifBuilder $gifBuilder)
    {
        if (!empty($configuration['useSample'])) {
            $gifBuilder->scalecmd = '-sample';
        }
        $options = [];
        if (!empty($configuration['maxWidth'])) {
            $options['maxW'] = $configuration['maxWidth'];
        }
        if (!empty($configuration['maxHeight'])) {
            $options['maxH'] = $configuration['maxHeight'];
        }
        if (!empty($configuration['minWidth'])) {
            $options['minW'] = $configuration['minWidth'];
        }
        if (!empty($configuration['minHeight'])) {
            $options['minH'] = $configuration['minHeight'];
        }
        
        $options['noScale'] = $configuration['noScale'];
        
        return $options;
    }
    
    /**
     * @return LocalImageProcessor
     */
    protected function getProcessor()
    {
        if (!$this->processor) {
            $this->processor = GeneralUtility::makeInstance(LocalImageProcessor::class);
        }
        return $this->processor;
    }
    
    /**
     * @return GraphicalFunctions
     */
    protected function getGraphicalFunctionsObject(): GraphicalFunctions
    {
        return GeneralUtility::makeInstance(GraphicalFunctions::class);
    }
    
}