<?php
namespace Helhum\ClassAliasLoader;

/*
 * This file is part of the class alias loader package.
 *
 * (c) Helmut Hummel <info@helhum.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

/**
 * This class loops over all packages that are installed by composer and
 * looks for configured class alias maps (in composer.json).
 * If at least one is found, the vendor/autoload.php file is rewritten to amend the composer class loader.
 * Otherwise it does nothing.
 */
class ClassAliasMapGenerator
{
    /**
     * @param \Composer\Script\Event $event
     * @return bool
     * @throws \Exception
     */
    static public function generateAliasMap(\Composer\Script\Event $event)
    {
        $composer = $event->getComposer();
        $config = $composer->getConfig();

        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
        $basePath = $filesystem->normalizePath(realpath(getcwd()));
        $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));
        $targetDir = $vendorPath . '/composer';
        $filesystem->ensureDirectoryExists($targetDir);

        $mainPackage = $composer->getPackage();
        $autoLoadGenerator = $composer->getAutoloadGenerator();
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $packageMap = $autoLoadGenerator->buildPackageMap($composer->getInstallationManager(), $mainPackage, $localRepo->getCanonicalPackages());

        $aliasToClassNameMapping = array();
        $classNameToAliasMapping = array();
        $classAliasMappingFound = false;

        foreach ($packageMap as $item) {
            list($package, $installPath) = $item;
            $aliasLoaderConfig = self::getAliasLoaderConfigFromPackage($package);
            if (!empty($aliasLoaderConfig['class-alias-maps'])) {
                if (!is_array($aliasLoaderConfig['class-alias-maps'])) {
                    throw new \Exception('"class-alias-maps" must be an array');
                }
                foreach ($aliasLoaderConfig['class-alias-maps'] as $mapFile) {
                    $mapFilePath = ($installPath ?: $basePath) . '/' . $filesystem->normalizePath($mapFile);
                    if (is_file($mapFilePath)) {
                        $packageAliasMap = require $mapFilePath;
                        if (!is_array($packageAliasMap)) {
                            throw new \Exception('"class alias maps" must return an array', 1422625075);
                        }
                        if (!empty($packageAliasMap)) {
                            $classAliasMappingFound = true;
                        }
                        foreach ($packageAliasMap as $aliasClassName => $className) {
                            $lowerCasedAliasClassName = strtolower($aliasClassName);
                            $aliasToClassNameMapping[$lowerCasedAliasClassName] = $className;
                            $classNameToAliasMapping[$className][$lowerCasedAliasClassName] = $lowerCasedAliasClassName;
                        }
                    }
                }
            }
        }

        $mainPackageAliasLoaderConfig = self::getAliasLoaderConfigFromPackage($mainPackage);
        $caseSensitiveClassLoading = $mainPackageAliasLoaderConfig['autoload-case-sensitivity'];

        if (!$classAliasMappingFound && $caseSensitiveClassLoading) {
            // No mapping found in any package and no insensitive class loading active. We return early and skip rewriting
            return false;
        }

        $caseSensitiveClassLoadingString = $caseSensitiveClassLoading ? 'true' : 'false';
        $event->getIO()->write('<info>Generating class alias map file</info>');
        self::generateAliasMapFile($aliasToClassNameMapping, $classNameToAliasMapping, $targetDir);

        $suffix = null;
        if (!$config->get('autoloader-suffix') && is_readable($vendorPath . '/autoload.php')) {
            $content = file_get_contents($vendorPath . '/autoload.php');
            if (preg_match('{ComposerAutoloaderInit([^:\s]+)::}', $content, $match)) {
                $suffix = $match[1];
            }
        }

        if (!$suffix) {
            $suffix = $config->get('autoloader-suffix') ?: md5(uniqid('', true));
        }

        $prependAutoloader = $config->get('prepend-autoloader') === false ? 'false' : 'true';

        $aliasLoaderInitClassContent = <<<EOF
<?php

// autoload_alias_loader_real.php @generated by helhum/class-alias-loader

class ClassAliasLoaderInit$suffix {

    private static \$loader;

    public static function getAliasLoader(\$composerClassLoader) {
        if (null !== self::\$loader) {
            return self::\$loader;
        }

        \$classAliasLoader = new Helhum\ClassAliasLoader\ClassAliasLoader(\$composerClassLoader);

        self::\$loader = \$classAliasLoader;
        Helhum\ClassAliasLoader\ClassAliasMap::setClassAliasLoader(\$classAliasLoader);

        \$classAliasMap = require __DIR__ . '/autoload_classaliasmap.php';
        \$classAliasLoader->setAliasMap(\$classAliasMap);
        \$classAliasLoader->setCaseSensitiveClassLoading($caseSensitiveClassLoadingString);
        \$classAliasLoader->register($prependAutoloader);

        return \$classAliasLoader;
    }
}

EOF;
        file_put_contents($targetDir . '/autoload_alias_loader_real.php', $aliasLoaderInitClassContent);

        if (!$caseSensitiveClassLoading) {
            $event->getIO()->write('<info>Re-writing class map to support case insensitive class loading</info>');
            $flags = $event->getFlags();
            $optimize = !empty($flags['optimize']) || $config->get('optimize-autoloader') || $config->get('classmap-authoritative');
            if (!$optimize) {
                $event->getIO()->write('<warning>Case insensitive class loading only works reliably if you use the optimize class loading feature of composer</warning>');
            }
            self::rewriteClassMapWithLowerCaseClassNames($targetDir);
        }

        $event->getIO()->write('<info>Inserting class alias loader into main autoload.php file</info>');
        static::modifyMainAutoloadFile($vendorPath . '/autoload.php', $suffix);

        return true;
    }

    /**
     * @param PackageInterface $package
     * @return array
     * @TODO: refactor into own config object
     */
    static protected function getAliasLoaderConfigFromPackage(PackageInterface $package)
    {
        $extraConfig = $package->getExtra();
        $aliasLoaderConfig = array(
                'class-alias-maps' => array(),
                'autoload-case-sensitivity' => true
        );
        if (isset($extraConfig['helhum/class-alias-loader']['class-alias-maps'])) {
            $aliasLoaderConfig['class-alias-maps'] = $extraConfig['helhum/class-alias-loader']['class-alias-maps'];
        }
        if (isset($extraConfig['helhum/class-alias-loader']['autoload-case-sensitivity'])) {
            $aliasLoaderConfig['autoload-case-sensitivity'] = (bool)$extraConfig['helhum/class-alias-loader']['autoload-case-sensitivity'];
        }

        return $aliasLoaderConfig;
    }

    /**
     * @param $autoloadFile
     * @param string $suffix
     */
    static protected function modifyMainAutoloadFile($autoloadFile, $suffix)
    {
        $originalAutoloadFileContent = file_get_contents($autoloadFile);
        preg_match('/return ComposerAutoloaderInit[^;]*;/', $originalAutoloadFileContent, $matches);
        $originalAutoloadFileContent = str_replace($matches[0], '', $originalAutoloadFileContent);
        $composerClassLoaderInit = str_replace(array('return ', ';'), '', $matches[0]);
        $autoloadFileContent = <<<EOF
$originalAutoloadFileContent

// autoload.php @generated by helhum/class-alias-loader

require_once __DIR__ . '/composer/autoload_alias_loader_real.php';

return ClassAliasLoaderInit$suffix::getAliasLoader($composerClassLoaderInit);

EOF;

        file_put_contents($autoloadFile, $autoloadFileContent);

    }

    /**
     * @param array $aliasToClassNameMapping
     * @param array $classNameToAliasMapping
     * @param string $targetDir
     */
    static protected function generateAliasMapFile(array $aliasToClassNameMapping, array $classNameToAliasMapping, $targetDir)
    {
        $exportArray = array(
                'aliasToClassNameMapping' => $aliasToClassNameMapping,
                'classNameToAliasMapping' => $classNameToAliasMapping
        );

        $fileContent = '<?php' . chr(10) . 'return ';
        $fileContent .= var_export($exportArray, true);
        $fileContent .= ';';

        file_put_contents($targetDir . '/autoload_classaliasmap.php', $fileContent);
    }

    /**
     * Rewrites the class map to have lowercased keys to be able to load classes with wrong casing
     * Defaults to case sensitivity (composer loader default)
     *
     * @param string $targetDir
     */
    static protected function rewriteClassMapWithLowerCaseClassNames($targetDir)
    {
        $classMapContents = file_get_contents($targetDir . '/autoload_classmap.php');
        $classMapContents = preg_replace_callback('/    \'[^\']*\' => /', function ($match) {
            return strtolower($match[0]);
        }, $classMapContents);
        file_put_contents($targetDir . '/autoload_classmap.php', $classMapContents);
    }

}
