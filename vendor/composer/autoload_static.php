<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit90ad93c7251d4f60daa9e545879c49e7
{
    public static $prefixLengthsPsr4 = array (
        'R' => 
        array (
            'R2\\Templating\\' => 14,
        ),
        'P' => 
        array (
            'Psr\\SimpleCache\\' => 16,
            'Psr\\Log\\' => 8,
        ),
        'M' => 
        array (
            'MioVisman\\NormEmail\\' => 20,
            'MioVisman\\Jevix\\' => 16,
        ),
        'F' => 
        array (
            'ForkBB\\' => 7,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'R2\\Templating\\' => 
        array (
            0 => __DIR__ . '/..' . '/artoodetoo/dirk/src',
        ),
        'Psr\\SimpleCache\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/simple-cache/src',
        ),
        'Psr\\Log\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/log/Psr/Log',
        ),
        'MioVisman\\NormEmail\\' => 
        array (
            0 => __DIR__ . '/..' . '/miovisman/normemail/src',
        ),
        'MioVisman\\Jevix\\' => 
        array (
            0 => __DIR__ . '/..' . '/miovisman/jevix/src',
        ),
        'ForkBB\\' => 
        array (
            0 => __DIR__ . '/../..' . '/app',
        ),
    );

    public static $prefixesPsr0 = array (
        'P' => 
        array (
            'Parserus' => 
            array (
                0 => __DIR__ . '/..' . '/miovisman/parserus',
            ),
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit90ad93c7251d4f60daa9e545879c49e7::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit90ad93c7251d4f60daa9e545879c49e7::$prefixDirsPsr4;
            $loader->prefixesPsr0 = ComposerStaticInit90ad93c7251d4f60daa9e545879c49e7::$prefixesPsr0;
            $loader->classMap = ComposerStaticInit90ad93c7251d4f60daa9e545879c49e7::$classMap;

        }, null, ClassLoader::class);
    }
}