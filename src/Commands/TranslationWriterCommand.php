<?php

declare(strict_types = 1);

namespace Drupal\helfi_platform_config\Commands;

use Drupal\Component\Gettext\PoItem;
use Drupal\Component\Gettext\PoStreamWriter;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\StringTranslation\TranslationManager;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes translation files for helfi_features.
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
   *
   * @param \Drupal\Core\Language\LanguageManager $languageManager
   *   The language manager.
   * @param \Drupal\Core\StringTranslation\TranslationManager $translationManager
   *   The translation manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The filesystem.
   */
  public function __construct(LanguageManager $languageManager, TranslationManager $translationManager, FileSystemInterface $fileSystem) {
    $this->languageManager = $languageManager;
    $this->translationManager = $translationManager;
    $this->fileSystem = $fileSystem;
  }

  /**
   * Create .po files from configuration files.
   *
   * @param string $feature
   *   The name of the feature.
   *
   * @command helfi:create-translations
   */
  public function createTranslations(string $feature) {
    $featurePath = $this->getModulePath($feature);

    $translationFileUrls = $this->getTranslationFiles($featurePath);
    $translationArrays = $this->parseTranslations($translationFileUrls);
    $translations = $this->combineTranslations($translationArrays);
    $this->writeTranslationFiles($translations, $featurePath);
  }

  /**
   * Get path for the feature.
   */
  private function getModulePath($module): string {
    return drupal_get_path('module', 'helfi_platform_config') . '/helfi_features/' . $module;
  }

  /**
   * Gets the translation files.
   *
   * @param string $basePath
   *   The module base path.
   *
   * @return array
   *   Translation file array.
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
        $uris[$file->filename] = $file->uri;
      }
      $languageFileUris[$language->getId()] = $uris;
      $allPossibleTranslations += $uris;
    }

    // Configuration files for default language resides in multiple locations.
    $defaultConfigurationLocations = [
      sprintf('%s/config/install', $basePath),
      sprintf('%s/config/optional', $basePath),
      sprintf('%s/config/override', $basePath),
    ];
    foreach ($defaultConfigurationLocations as $location) {
      if (is_dir($location)) {
        foreach ($this->fileSystem->scanDirectory($location, '/\.yml/') as $file) {
          if (array_key_exists($file->filename, $allPossibleTranslations)) {
            $defaultLanguageFiles[$file->filename] = $file->uri;
          }
        }
      }
    }

    if (empty($defaultLanguageFiles)) {
      throw new \Exception("Could not find any files for default languages in optional or install forlders in  " . $basePath);
    }

    $languageFileUris[$this->languageManager->getDefaultLanguage()->getId()] = $defaultLanguageFiles;

    return $languageFileUris;
  }

  /**
   * Parse the configurations from yml into array.
   *
   * @param array $languageFiles
   *   Language files.
   *
   * @return array
   *   Translations as flat array.
   *
   * @throws \Exception
   */
  private function parseTranslations(array $languageFiles) {
    $defaultLanguage = $this->languageManager->getDefaultLanguage();
    $translations = [];

    $defaultLanguageFiles = $languageFiles[$defaultLanguage->getId()];
    unset($languageFiles[$defaultLanguage->getId()]);

    $translations[$defaultLanguage->getId()] = [];
    foreach ($defaultLanguageFiles as $filename => $file) {
      if (!$this->translationExists($filename, $languageFiles)) {
        unset($defaultLanguageFiles[$filename]);
        continue;
      }

      $yml = Yaml::parseFile($file);
      $flatten = $this->arrayFlatten($yml, $filename);
      $translations[$defaultLanguage->getId()][$filename] = $flatten;
    }

    foreach ($languageFiles as $langcode => $files) {
      $translations[$langcode] = isset($translations[$langcode]) ?: [];
      foreach ($files as $filename => $file) {
        $yml = Yaml::parseFile($file);
        $flatten = $this->arrayFlatten($yml, $filename);
        $translations[$langcode][$filename] = $flatten;
      }
    }
    return $translations;
  }

  /**
   * Turn translation arrays into single array.
   *
   * @param array $allTranslations
   *   Translation ymls parsed into arrays.
   *
   * @return array
   *   Translation as value and default translation as key.
   */
  private function combineTranslations(array $allTranslations): array {
    $finalTranslations = [];
    $original = $allTranslations[$this->languageManager->getDefaultLanguage()->getId()];
    unset($allTranslations[$this->languageManager->getDefaultLanguage()->getId()]);
    foreach ($allTranslations as $langcode => $translationsByLanguage) {
      foreach ($translationsByLanguage as $file => $translationsByFile) {
        foreach ($translationsByFile as $translationKey => $translation) {
          if (!isset($original[$file])) {
            continue;
          }
          $translations = $original[$file];
          if (isset($translations[$translationKey])) {
            $finalTranslations[$langcode][$translations[$translationKey]] = $translation;
          }
        }
        if (isset($finalTranslations[$langcode])) {
          uksort($finalTranslations[$langcode], 'strcasecmp');
        }
      }
    }
    return $finalTranslations;
  }

  /**
   * Write the po files.
   *
   * @param array $translationsByLanguage
   *   Translation array.
   * @param string $basePath
   *   Feature base path.
   */
  private function writeTranslationFiles(array $translationsByLanguage, string $basePath): void {
    if (!is_dir($basePath . '/translations')) {
      mkdir(($basePath . '/translations'), 0755);
    }

    foreach ($translationsByLanguage as $langcode => $translations) {
      $filename = $langcode . '.po';
      $uri = sprintf('%s/translations/%s', $basePath, $filename);

      $writer = new PoStreamWriter();

      $writer->setURI($uri);
      $writer->open();

      $item = new PoItem();
      $item->setLangcode($langcode);
      $item->setFromArray(['source' => '', 'translation' => '']);
      $writer->writeItem($item);

      foreach ($translations as $msgid => $msgstr) {
        $item = new PoItem();
        $item->setLangcode($langcode);
        $item->setFromArray(['source' => $msgid, 'translation' => $msgstr]);
        $writer->writeItem($item);
      }

      $writer->close();
    }
    $this->writeln(sprintf('Operation finished. Check "%s" for the end result.', $basePath . '/translations'));
  }

  /**
   * Flatten array.
   *
   * @param array $array
   *   Array to flatten.
   * @param string $fullname
   *   Full name for the key generated by recursion.
   *
   * @return array
   *   Flattened array.
   */
  private function arrayFlatten(array $array, string $fullname = ''): array {
    $return = [];
    foreach ($array as $key => $value) {
      $fullpath = $fullname . '.' . $key;
      if (is_array($value)) {
        $return = array_merge($return, $this->arrayFlatten($value, $fullpath));
      }
      else {
        $return[$fullpath] = $value;
      }
    }
    return $return;
  }

  /**
   * Check if file exist in any language.
   *
   * @param string $filename
   *   Name of the file.
   * @param array $filesByLanguage
   *   Files by language.
   *
   * @return bool
   *   Translation file exists.
   */
  private function translationExists(string $filename, array $filesByLanguage): bool {
    foreach ($filesByLanguage as $files) {
      if (array_key_exists($filename, $files)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
