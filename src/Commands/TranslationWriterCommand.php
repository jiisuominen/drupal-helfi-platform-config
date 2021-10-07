<?php

declare(strict_types = 1);

namespace Drupal\helfi_platform_config\Commands;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\StringTranslation\TranslationManager;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes translation files.
 *
 * @package Drupal\drush9_custom_commands\Commands
 */
class TranslationWriterCommand extends DrushCommands {

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManager
   */
  protected $languageManager;

  /**
   * The translation manager.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $translationManager;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructor.
   * @param LanguageManager $languageManager
   * @param TranslationManager $translationManager
   * @param FileSystemInterface $fileSystem
   */
  public function __construct(LanguageManager $languageManager, TranslationManager $translationManager, FileSystemInterface $fileSystem) {
    $this->languageManager = $languageManager;
    $this->translationManager = $translationManager;
    $this->fileSystem = $fileSystem;
  }

  /**
   * Create .po files from configuration files.
   *
   * @param string $module
   *   The module or feature name.
   *
   * @option feature
   *   Flag if you want to create the translations for helfi_platform_config features.
   *
   * @command helfi:create-translations
   */
  public function createTranslations($module, $options = ['feature' => FALSE]) {
    $modulePath = $this->getModulePath($module, $options['feature']);

    $translationFileUrls = $this->getTranslationFiles($modulePath);
    $translationArrays = $this->parseTranslations($translationFileUrls);
    $translations = $this->combineTranslations($translationArrays);
    $this->sortTranslations($translations);
    $this->writeTranslationFiles($translations, $modulePath);
  }


  private function getModulePath($module, $feature): string {
    if ($feature) {
      return drupal_get_path('module', 'helfi_platform_config') . '/helfi_features/'.$module ;
    }
    return drupal_get_path('module', $module);
  }

  /**
   * Gets the translation files.
   *
   * @param string $moduleName
   *   The module name.
   *
   * @return \stdClass[]
   *   Translation file objects.
   */
  private function getTranslationFiles(string $basePath) : array {
    $languages = $this->languageManager->getLanguages();

    $languageFileUris = [];
    $allPossibleTranslations = [];
    foreach ($languages as $language) {
      $dir = sprintf('%s/config/language/%s', $basePath, $language->getId());
      if (!is_dir($dir)) {
        continue;
      }

      $uris = [];
      foreach ($this->fileSystem->scanDirectory($dir, '/\.yml/') as $file) {
        $uris[$file->name] = $file->uri;
      }
      $languageFileUris[$language->getId()] = $uris;
      $allPossibleTranslations += $uris;
    }

    // Configuration files for default languages resides elsewhere.
    $dir = sprintf('%s/config/install', $basePath);
    $defaultLanguageFiles = [];
    if (is_dir($dir)) {
      foreach ($this->fileSystem->scanDirectory($dir, '/\.yml/') as $file) {
        if (array_key_exists($file->name, $allPossibleTranslations)) {
          $defaultLanguageFiles[$file->name] = $file->uri;
        }
      }
    }

    $dir = sprintf('%s/config/optional', $basePath);
    if (is_dir($dir)) {
      foreach ($this->fileSystem->scanDirectory($dir, '/\.yml/') as $file) {
        if (array_key_exists($file->name, $allPossibleTranslations)) {
          $defaultLanguageFiles[$file->name] = $file->uri;
        }
      }
    }

    if (empty($defaultLanguageFiles)) {
      throw new \Exception("Could not find any files for default languages in optional or install forlders in  ". $basePath);
    }

    $languageFileUris[$this->languageManager->getDefaultLanguage()->getId()] = $defaultLanguageFiles;

    return $languageFileUris;
  }

  /**
   * Parse the configurations from yml into array.
   *
   * @param array $languageFiles
   * @return array
   * @throws \Exception
   */
  private function parseTranslations(array $languageFiles) {
    $defaultLanguage = $this->languageManager->getDefaultLanguage();
    $translations = [];

    if (!isset($languageFiles[$defaultLanguage->getId()])) {
      throw new \Exception('Default language configurations not found.');
    }

    $defaultLanguageFiles = $languageFiles[$defaultLanguage->getId()];
    unset($languageFiles[$defaultLanguage->getId()]);

    $translations[$defaultLanguage->getId()] = [];
    foreach($defaultLanguageFiles as $filename => $file) {
      if (!$this->translationExists($filename, $languageFiles)) {
        unset($defaultLanguageFiles[$filename]);
        continue;
      }

      $yml = Yaml::parseFile($file);
      $flatten = $this->array_flatten($yml);
      $translations[$defaultLanguage->getId()] += $flatten;
    }

    foreach ($languageFiles as $langcode => $files) {
      $translations[$langcode] = isset($translations[$langcode]) ?: [];
      foreach($files as $file) {
        $yml = Yaml::parseFile($file);
        $flatten = $this->array_flatten($yml);
        $translations[$langcode][$file] = $flatten;
      }
    }
    return $translations;
  }

  /**
   * Compare the original language with translation files and combine the results into key:values.
   *
   * @param array $translations
   * @return array
   */
  private function combineTranslations(array $allTranslations): array {
    $finalTranslations = [];
    $original = $allTranslations[$this->languageManager->getDefaultLanguage()->getId()];
    unset($allTranslations[$this->languageManager->getDefaultLanguage()->getId()]);
    foreach ($allTranslations as $langcode => $translationsByLanguage) {
      foreach ($translationsByLanguage as $file => $translationsByFile){
        foreach($translationsByFile as $translationKey => $translation) {
          if (isset($original[$translationKey])) {
            $finalTranslations[$langcode][$original[$translationKey]] = $translation;
          }
        }
        ksort($finalTranslations[$langcode]);
      }
    }
    return $finalTranslations;
  }

  /**
   * Write the po files.
   *
   * @param array $translations
   *   Translation array.
   */
  private function writeTranslationFiles(array $translationsByLanguage, string $basePath): void {
    if (!is_dir($basePath.'/translations')) {
      mkdir(($basePath.'/translations'), 755);
    }

    foreach ($translationsByLanguage as $langcode => $translations) {
      $filename = $langcode.'.po';
      $dir = sprintf('%s/translations/%s', $basePath, $filename);

      $fh = fopen($dir, 'w+');

      fwrite($fh, "#\n");
      fwrite($fh, "msgid \"\"\n");
      fwrite($fh, "msgstr \"\"\n");

      foreach ($translations as $msgid => $msgstr) {
        $key = addslashes((string)$msgid);
        $value = addslashes((string)$msgstr);
        fwrite($fh, "\n");
        fwrite($fh, "msgid \"$key\"\n");
        fwrite($fh, "msgstr \"$value\"\n");
      }
      fclose($fh);
    }
    $this->writeln(sprintf('Operation finished. Check "%s" for the end result.', $basePath.'/translations'));
  }

  private function array_flatten($array): array {
    $return = array();
    foreach ($array as $key => $value) {
      if (is_array($value)){
        $return = array_merge($return, $this->array_flatten($value));
      } else {
        $return[$key] = $value;
      }
    }
    return $return;
  }

  private function translationExists($filename, $filesByLanguage): bool {
    foreach($filesByLanguage as $files) {
      if (array_key_exists($filename, $files)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
