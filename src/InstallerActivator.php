<?php

namespace DrupalContribInstaller\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

class InstallerActivator implements PluginInterface, EventSubscriberInterface {
  
  protected $io;

  public function activate(Composer $composer, IOInterface $io) {
    $this->io = $io;
    $composer->getInstallationManager()->addInstaller(new CoreInstaller($io, $composer));
    $composer->getInstallationManager()->addInstaller(new ExtensionInstaller($io, $composer));
  }

  public static function getSubscribedEvents() {
    return [
      ScriptEvents::POST_UPDATE_CMD => [
        ['ensureSymlinks'],
        ['ensureGitExclusions'],
      ],
      // Priority is set to 10 to ensure that these subscribers are executed
      // before cweagans/composer-patches's subscribers are executed.
      PackageEvents::PRE_PACKAGE_INSTALL => ['modifyPatches', 10],
      PackageEvents::PRE_PACKAGE_UPDATE => ['modifyPatches', 10],
    ];
  }

  public function ensureGitExclusions() {
    $filesystem = new Filesystem();
    $destination = realpath('web/.git/info') . '/exclude';
    $exclude_file_exists = file_exists($destination);
    $exclude_file_already_modified = $exclude_file_exists && !empty(preg_grep('/^' . preg_quote('### BEGIN ### Added by drupal/contrib-installer', '/') . '$/', file($destination) ?: []));
    if ($exclude_file_already_modified) {
      return;
    }
    $source = realpath('vendor/drupal/contrib-installer') . '/gitexclude.txt';
    $stream = fopen($source, 'r');
    file_put_contents($destination, $stream, FILE_APPEND);
    fclose($stream);
  }

  public function ensureSymlinks() {
    //"drush/Commands/contrib/{$name}": ["type:drupal-drush"],
    foreach (['contrib', 'custom'] as $extension_category) {
      $directories = array_map(function ($directory_mapping) {
        return array_map('realpath', $directory_mapping);
      }, [
        'modules' => ["{$extension_category}/modules", 'web/modules'],
        'profiles' => ["{$extension_category}/profiles", 'web/modules'],
        'themes' => ["{$extension_category}/themes", 'web/modules'],
        'libraries' => ["{$extension_category}/libraries", 'web/libraries'],
        'drush' => ["{$extension_category}/drush", 'web/drush'],
      ]);
      foreach ($directories as $extension_type => $directory_mapping) {
        list($target_directory, $source_directory) = $directory_mapping;
        $source_path = $source_directory;
        if ($extension_type !== 'drush') {
          $source_path .= "/{$extension_category}";
        }
        $this->ensureSymlink($target_directory, $source_path);
      }
    }
    $this->ensureSymlink(realpath('vendor'), realpath('web') . '/vendor');
  }

  protected function ensureSymlink($target, $source) {
    $filesystem = new Filesystem();
    if (!file_exists($source) && file_exists($target)) {
      $filesystem->relativeSymlink($target, $source);
    }
  }

  public function modifyPatches(PackageEvent $event) {
    $operations = $event->getOperations();
    $this->io->write('<info>Pointing drupal/core patches to drupal/drupal instead.</info>');
    foreach ($operations as $operation) {
      if ($operation->getJobType() == 'install' || $operation->getJobType() == 'update') {
        $package = $this->getPackageFromOperation($operation);
        $extra = $package->getExtra();
        if (!empty($extra['patches']['drupal/core']) && method_exists($package, 'setExtra')) {
          $core_patches = $extra['patches']['drupal/core'];
          $extra['patches']['drupal/drupal'] = array_merge($core_patches, $extra['patches']['drupal/drupal'] ?? []);
          unset($extra['patches']['drupal/core']);
          $package->setExtra($extra);
        }
      }
    }
  }

  /**
   * Get a Package object from an OperationInterface object.
   *
   * Duplicated from cweagans/composer-patches.
   *
   * @see \cweagans\Composer\Patches::getPackageFromOperation()
   */
  protected function getPackageFromOperation(OperationInterface $operation) {
    if ($operation instanceof InstallOperation) {
      $package = $operation->getPackage();
    }
    elseif ($operation instanceof UpdateOperation) {
      $package = $operation->getTargetPackage();
    }
    else {
      throw new \Exception('Unknown operation: ' . get_class($operation));
    }

    return $package;
  }

}
