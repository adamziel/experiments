<?php

declare(strict_types=1);

/*
 * static-php-cli Builder class for the `branchfs` extension.
 *
 * branchfs is NOT an upstream PHP extension — it lives in this repo at
 * branched-wp/ext/. To get it compiled *into* the static PHP binary spc
 * produces (so we don't need dlopen at runtime, which musl-static PHP
 * doesn't support), we register branchfs with spc as a "builtin" ext
 * and stage its sources into source/php-src/ext/branchfs/ right before
 * ./buildconf runs. buildconf's glob over ext/ * /config.m4 then picks
 * up our PHP_ARG_ENABLE(branchfs, ...) declaration, so the subsequent
 * ./configure run accepts --enable-branchfs, and make links branchfs
 * into libphp (and into the cli binary).
 *
 * CI sets SPC_BRANCHFS_SOURCE to the directory containing
 * branchfs.c / branchfs.h / config.m4 before invoking `spc build`.
 *
 * References:
 *   - spc extension registration:
 *     https://github.com/crazywhalecc/static-php-cli/blob/2.4.0/config/ext.json
 *   - patchBeforeBuildconf hook contract:
 *     src/SPC/builder/Extension.php :: patchBeforeBuildconf()
 *   - existing "modify ext source just before buildconf" precedent:
 *     src/SPC/builder/extension/simdjson.php
 */

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\exception\FileSystemException;
use SPC\exception\WrongUsageException;
use SPC\util\CustomExt;

#[CustomExt('branchfs')]
class branchfs extends Extension
{
    /**
     * Files branchfs's config.m4 references. Missing any of these means
     * the ext source dir wasn't staged correctly — fail fast with a
     * clear message rather than letting buildconf emit its own confusing
     * m4 error.
     */
    private const REQUIRED_FILES = ['branchfs.c', 'branchfs.h', 'config.m4'];

    public function patchBeforeBuildconf(): bool
    {
        $src = getenv('SPC_BRANCHFS_SOURCE');
        if ($src === false || $src === '') {
            throw new WrongUsageException(
                'branchfs extension is enabled but SPC_BRANCHFS_SOURCE is not set. '
                . 'Point it at the branched-wp/ext/ directory before running spc build.'
            );
        }
        if (!is_dir($src)) {
            throw new WrongUsageException("SPC_BRANCHFS_SOURCE={$src} is not a directory");
        }

        $dst = SOURCE_PATH . '/php-src/ext/branchfs';
        if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
            throw new FileSystemException("could not create {$dst}");
        }

        foreach (self::REQUIRED_FILES as $name) {
            $from = rtrim($src, '/') . '/' . $name;
            if (!is_file($from)) {
                throw new WrongUsageException("SPC_BRANCHFS_SOURCE is missing {$name} (looked at {$from})");
            }
            if (!copy($from, $dst . '/' . $name)) {
                throw new FileSystemException("copy {$from} -> {$dst}/{$name} failed");
            }
        }

        return true;
    }
}
