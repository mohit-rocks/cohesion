<?php

namespace Drupal\cohesion_sync\Form;

use Drupal\cohesion\Entity\CohesionSettingsInterface;
use Drupal\cohesion\UsagePluginManager;
use Drupal\cohesion_sync\Config\CohesionFullPackageStorage;
use Drupal\cohesion_sync\PackagerManager;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\cohesion_sync\Config\CohesionFileStorage;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

/**
 * Provides a form for exporting a single configuration file.
 *
 * @internal
 */
class ExportAllForm extends ExportFormBase {

  const FILE_GENERATED_STATE_KEY = 'cohesion_sync.package_export_file_generated';

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Cohesion Storage service.
   *
   * @var \Drupal\cohesion_sync\Config\CohesionFullPackageStorage
   */
  protected $fullPackageStorage;

  /**
   * The file download controller.
   *
   * @var bool
   */
  protected $fileDownloadReady = FALSE;

  /**
   * Module Handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Site name in a format suitable for filename.
   *
   * @var string
   */
  protected $siteName;

  /**
   * Date Formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('config.storage'),
      $container->get('cohesion_sync.packager'),
      $container->get('entity.repository'),
      $container->get('plugin.manager.usage.processor'),
      $container->get('file_system'),
      $container->get('cohesion_sync.full_package_storage'),
      $container->get('module_handler'),
      $container->get('state'),
      $container->get('date.formatter')
    );
  }

  /**
   * ExportFormBase constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   Entity Manager.
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   Config storage.
   * @param \Drupal\cohesion_sync\PackagerManager $packager_manager
   *   Package manager.
   * @param \Drupal\Core\Entity\EntityRepository $entity_repository
   *   Entity Repository.
   * @param \Drupal\cohesion\UsagePluginManager $usage_plugin_manager
   *   Usage plugin manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   * @param \Drupal\cohesion_sync\Config\CohesionFullPackageStorage $cohesion_full_package_storage
   *   Cohesion Storage service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module Handler service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key value store.
   * @paran \Drupal\Core\Datetime\DateFormatterInterface
   *   Date Formatter service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_manager,
    StorageInterface $config_storage,
    PackagerManager $packager_manager,
    EntityRepository $entity_repository,
    UsagePluginManager $usage_plugin_manager,
    FileSystemInterface $file_system,
    CohesionFullPackageStorage $cohesion_full_package_storage,
    ModuleHandlerInterface $moduleHandler,
    StateInterface $state,
    DateFormatterInterface $dateFormatter
  ) {
    parent::__construct(
      $config_factory,
      $entity_manager,
      $config_storage,
      $packager_manager,
      $entity_repository,
      $usage_plugin_manager
    );

    $this->configSyncSettings = $this->config('cohesion.sync.settings');
    $this->usagePluginManager = $usage_plugin_manager;
    $this->fileSystem = $file_system;
    $this->fullPackageStorage = $cohesion_full_package_storage;
    $this->moduleHandler = $moduleHandler;
    $this->state = $state;
    $this->siteName = preg_replace('/[^a-z0-9]+/', '-', strtolower($this->config('system.site')->get('name')));
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dx8_sync_export_all_form';
  }

  /**
   * Gets legacy filename of this export.
   *
   * @return string
   *   Filename using site name and .package.yml as its postfix.
   */
  private function getLegacyExportFilename() {
    return $this->getSiteName() . '.package.yml';
  }

  /**
   * Gets filename of this export.
   *
   * @return string
   *   Filename using site name and .tar.gz as its postfix.
   */
  private function getExportFilename() {
    return $this->getSiteName() . '.tar.gz';
  }

  /**
   * Get site name.
   *
   * @return string
   *   Cleaned up site name to use in filename
   */
  private function getSiteName(): string {
    return $this->siteName;
  }

  /**
   * Get File URI.
   *
   * @return string
   *   Gets file URI in temporary directory.
   */
  private function getFileUri(): string {
    return 'temporary://' . $this->getExportFilename();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['help'] = [
      '#markup' => $this->t('Export and download the full Site Studio configuration of this site including all dependencies and assets.'),
    ];

    if ($this->entityTypesAvailable() === FALSE) {
      $this->showNoEntityTypesMessage();

      return $form;
    }

    $form['full_package_export'] = [
      '#type' => 'details',
      '#title' => $this
        ->t('Full package export'),
      '#open' => TRUE,
    ];

    if ($this->state->get(self::FILE_GENERATED_STATE_KEY) && is_file($this->getFileUri())) {
      $stats = stat($this->getFileUri());
      $form['full_package_export']['file'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['file-description-container'],
        ],
      ];
      $form['full_package_export']['file']['label'] = [
        '#type' => 'item',
        '#plain_text' => 'Full package export generated',
      ];
      $form['full_package_export']['file']['size'] = [
        '#type' => 'item',
        '#plain_text' => 'File size: ' . format_size($stats['size']),
      ];
      $form['full_package_export']['file']['changed'] = [
        '#type' => 'item',
        '#plain_text' => 'File last generated ' . $this->dateFormatter->format($stats['mtime']),
      ];
      $form['full_package_export']['file']['link'] = [
        '#title' => $this
          ->t('Download :filename', [':filename' => $this->getExportFilename()]),
        '#type' => 'link',
        '#url' => Url::fromRoute('cohesion_sync.export_all.download', ['filename' => $this->getExportFilename()]),
      ];
      $form['full_package_export']['actions'] = $this->addActions('Regenerate file');
      $form['#attached']['library'][] = 'cohesion_sync/full-package-export-form';
    }
    else {
      $this->deleteFile();
      $form['full_package_export']['#description'] = $this->t('Please use "Generate file" to prepare package export file for download.');
      $form['full_package_export']['actions'] = $this->addActions('Generate file', TRUE);
    }

    // Legacy package export.
    $form['legacy'] = [
      '#type' => 'details',
      '#title' => $this
        ->t('Legacy full package export'),
      '#open' => FALSE,
    ];
    $form['legacy']['filename'] = [
      '#prefix' => '<p><em class="placeholder">',
      '#suffix' => '</em></p>',
      '#markup' => $this->getLegacyExportFilename(),
    ];
    $this->addLegacyActionsToForm($form);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    switch ($form_state->getTriggeringElement()['#id']) {
      case 'generate':
        $batch = $this->generatePackageExportBatch();
        $this->state->delete(self::FILE_GENERATED_STATE_KEY);
        batch_set($batch);
        break;

      case 'remove':
        $this->state->delete(self::FILE_GENERATED_STATE_KEY);
        $this->deleteFile();
        $this->messenger()->addStatus($this->t('Package file has been successfully removed.'));
        break;

      case 'legacy_download':
        $this->handleLegacyDownloadSubmit($form, $form_state);
    }
  }

  /**
   * Generates Package Export batch.
   *
   * @return array
   *   Batch with operations limited by full_export_settings configuration.
   */
  public function generatePackageExportBatch() {
    $config = $this->fullPackageStorage->listAll();
    $files = $this->fullPackageStorage->getStorageFileList();
    $all_entries = array_merge($config, $files);
    $limit = $this->configSyncSettings->get('full_export_limit') ?? 10;
    $result = array_chunk($all_entries, $limit, TRUE);
    $num_operations = count($result);

    $operations = [];
    foreach ($result as $key => $value) {
      $operations[] = [
        [$this, 'processExportBatch'],
        [$key, $value],
      ];
    }

    return [
      'title' => $this->t('Running @num batches to process @count entities.', [
        '@num' => $num_operations,
        '@count' => count($all_entries),
      ]),
      'operations' => $operations,
      'finished' => [$this, 'packageExportFinished'],
    ];
  }

  /**
   * Handles legacy package downloads.
   *
   * @param array $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form State object.
   */
  protected function handleLegacyDownloadSubmit(array &$form, FormStateInterface $form_state) {
    // Build the excluded entity types up.
    $excluded_entity_type_ids = [];
    foreach ($this->configSyncSettings->get('enabled_entity_types') as $entity_type_id => $enabled) {
      if (!$enabled) {
        $excluded_entity_type_ids[] = $entity_type_id;
      }
    }

    // Loop over each entity type to get all the entities.
    $entities = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type => $definition) {
      if ($definition->entityClassImplements(CohesionSettingsInterface::class) && !in_array($entity_type, $excluded_entity_type_ids) && $entity_type !== 'custom_style_type') {
        try {
          $entity_storage = $this->entityTypeManager->getStorage($entity_type);
        }
        catch (\Exception $e) {
          continue;
        }

        /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
        foreach ($entity_storage->loadMultiple() as $entity) {
          if ($entity->status()) {
            $entities[] = $entity;
          }
        }
      }
    }

    // Force a download.
    $response = $this->packagerManager->sendYamlDownload($this->getLegacyExportFilename(), $entities, $excluded_entity_type_ids);
    try {
      $response->setContentDisposition('attachment', $this->getLegacyExportFilename());
      $form_state->setResponse($response);
    }
    catch (\Throwable $e) {
      // Failed, to build, so ignore the response and just show the error.
    }
  }

  /**
   * {@inheritdoc}
   *
   * @testme
   */
  protected function buildFileExportEntry($entity) {
    /** @var \Drupal\file\Entity\File $entity */
    $struct = [];

    // Get all the field values into the struct.
    foreach ($entity->getFields() as $field_key => $value) {
      // Get the value of the field.
      if ($value = $entity->get($field_key)->getValue()) {
        $value = reset($value);
      }
      else {
        continue;
      }

      // Add it to the export.
      if (isset($value['value'])) {
        $struct[$field_key] = $value['value'];
      }
    }

    return $struct;
  }

  /**
   * {@inheritDoc}
   */
  public function encode($data): string {
    if (!is_array($data)) {
      return FALSE;
    }

    // Json values multilne.
    if (isset($data['json_values']) && is_string($data['json_values'])) {
      $data['json_values'] = $this->prettyPrintJson($data['json_values']);
    }

    if (isset($data['json_mapper']) && is_string($data['json_mapper'])) {
      $data['json_mapper'] = $this->prettyPrintJson($data['json_mapper']);
    }

    // Package settings multiline.
    if (isset($data['type']) && $data['type'] == 'cohesion_sync_package' && isset($data['settings']) && is_string($data['settings'])) {
      $data['settings'] = $this->prettyPrintJson($data['settings']);
    }

    try {
      return SymfonyYaml::dump($data, PHP_INT_MAX, 2, SymfonyYaml::DUMP_EXCEPTION_ON_INVALID_TYPE + SymfonyYaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
    catch (\Exception $e) {
      throw new InvalidDataTypeException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Return a json pretty printed.
   *
   * @param string $json
   *   String containing JSON.
   *
   * @return string
   *   Pretty printed if possible or original JSON.
   */
  protected function prettyPrintJson(string $json): string {
    $decoded = json_decode($json);
    if (json_last_error() === JSON_ERROR_NONE) {
      return json_encode($decoded, JSON_PRETTY_PRINT);
    }

    return $json;
  }

  /**
   * Processes export batch.
   *
   * @param int $index
   *   Batch index.
   * @param array $batch
   *   Current batch operations set.
   * @param array $context
   *   Batch context.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processExportBatch(int $index, array $batch, array &$context) {
    if ($index === 0) {
      $this->deleteFile();
      $context['sandbox'] = [];
      $context['sandbox']['progress'] = 0;
    }
    $archiver = new ArchiveTar($this->getFileUri(), 'gz');

    foreach ($batch as $key => $value) {
      if (Uuid::isValid($key) && $value === 'file') {
        $file = $this->entityTypeManager->getStorage('file')->loadByProperties(['uuid' => $key]);
        $file = reset($file);
        if ($file instanceof FileInterface) {
          $entry = $this->buildFileExportEntry($file);
          $files[$file->getConfigDependencyName()] = $entry;
          $content = file_get_contents($file->getFileUri());
          $write_operation = $archiver->addString($file->getFilename(), $content, $file->getCreatedTime());
        }
      }
      elseif ($config_item = $this->fullPackageStorage->read($value)) {
        $write_operation = $archiver->addString("$value.yml", $this->encode($config_item));
      }
    }

    if (is_array($context['results'])) {
      $context['results'] = array_merge($context['results'], $files);
    }
    else {
      $context['results'] = $files;
    }
    $context['message'] = t('Running batch @index - @details',
      ['@index' => $index + 1, '@details' => 'Processing ' . count($batch) . ' entities in this batch.']
    );
    $context['sandbox']['progress']++;
  }

  /**
   * Package exort finished callback.
   *
   * @param $success
   *   Successful operations.
   * @param $results
   *   Batch process results - contains metadata required for generating exported files index.
   */
  public function packageExportFinished($success, $results) {
    if ($success && !empty($results)) {
      $archiver = new ArchiveTar($this->getFileUri(), 'gz');
      $archiver->addString(CohesionFileStorage::FILE_INDEX_FILENAME, json_encode($results, JSON_PRETTY_PRINT));
    }

    $this->state->set(self::FILE_GENERATED_STATE_KEY, TRUE);
    $this->messenger()->addStatus($this->t('Package file has been successfully generated.'));
  }

  /**
   * Attempts to delete existing package file.
   */
  private function deleteFile() {
    try {
      $this->fileSystem->delete($this->getFileUri());
    }
    catch (FileException $e) {
      // Ignore failed deletes.
    }
  }

}
