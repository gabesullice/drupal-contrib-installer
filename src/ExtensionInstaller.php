<?php

namespace DrupalContribInstaller\Composer;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;

class ExtensionInstaller extends LibraryInstaller {

  public function install(InstalledRepositoryInterface $repo, PackageInterface $package) {
    $extra = $package->getExtra();
    if (!empty($extra['patches']['drupal/core']) && method_exists($package, 'setExtra')) {
      $core_patches = $extra['patches']['drupal/core'];
      $extra['patches']['drupal/drupal'] = array_merge($core_patches, $extra['patches']['drupal/drupal'] ?? []);
      unset($extra['patches']['drupal/core']);
      $package->setExtra($extra);
    }
    parent::install($repo, $package);
  }

  public function getInstallPath(PackageInterface $package) {
    $subtype = substr($package->getType(), strlen('drupal-'));
    $name = explode('/', $package->getPrettyName(), 2)[1];
    switch ($subtype) {
      case 'module':
        return "contrib/modules/{$name}";
      case 'profile':
        return "contrib/profiles/{$name}";
      case 'theme':
        return "contrib/themes/{$name}";
      case 'drush':
        return "contrib/drush/Commands/{$name}";
      case 'custom-module':
        return "custom/modules/{$name}";
      case 'custom-theme':
        return "custom/themes/{$name}";
      default:
        return parent::getInstallPath($package);
    }
  }

  public function supports($package_type) {
    return strpos($package_type, 'drupal-') === 0 && $package_type !== 'drupal-core';
  }

}
