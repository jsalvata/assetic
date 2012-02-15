<?php

/*
 * This file is part of the Assetic package, an OpenSky project.
 *
 * (c) 2010-2011 OpenSky Project Inc
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Assetic\Filter;

use Assetic\Asset\AssetInterface;
use Assetic\Filter\FilterInterface;
use Assetic\Util\ProcessBuilder;

/**
 * SmartSprites filter
 *
 * USAGE:
 *
 * Configure this filter as the FIRST FILTER in the filter chain -- it 
 * needs to work on the original files from disk.
 *
 * <pre>
 * assetic:
 *    filters:
 *        smartsprites:
 *            apply_to: '\.(s?css|sprite)$'
 *            java: /opt/java/32/jre1.6.0_30/bin/java
 *            classpath:
 *                - %kernel.root_dir%/Resources/java/smartsprites-0.2.6.jar
 *                - %kernel.root_dir%/Resources/java/args4j-2.0.9.jar
 *                - %kernel.root_dir%/Resources/java/google-collections-1.0-rc2.jar
 *                - %kernel.root_dir%/Resources/java/commons-lang-2.3.jar
 *                - %kernel.root_dir%/Resources/java/commons-io-1.4.jar
 *            log_level: IE6NOTICE # WARN or IE6NOTICE or INFO
 * </pre>
 *
 * Put the sprite image directive as the single line in a file named
 * mysprite.sprite:
 * 
 *   /** sprite: mysprite; sprite-image: url(/image/${sprite}-${date}.png); sprite-layout: vertical * /
 * 
 * Register the sprite with assetic so it gets dumped on cache warm-up:
 *
 * <pre>
 * assetic:
 *     ...
 *     assets:
 *        sprite_gif:
 *            inputs: [ '@SalirFrontendBundle/Resources/public/css/sprite-gif.sprite' ]
 *            output: images/sprite.gif
 * </pre>
 *
 * When assetic processes the first file containing a SmartSprites directive,
 * it actually processes all such files in the smae bundle. The results
 * are kept in temporary files and updated only when a change is detected.
 * 
 * NOTES:
 * * SmartSprites 2.0.6 works. 2.0.8 doesn't, because it rewrites the
 *   sprite image urls to be relative, which doesn't work in an assetic
 *   environment.
 *
 * @author Jordi Salvat i Alabart <jordi.salvat.i.alabart@gmail.com>
 */
class SmartSpritesFilter implements FilterInterface
{
    private $java;
    private $classpath;
    private $logLevel;
    private $spritePngDepth;
    private $spritePngIe6;
    private $cssFileEncoding;

    private $cacheDir="/tmp/spritify";
    private $spriteFiles="\\.sprite$";
    private $cssFiles="\\.s?css$";

    public function __construct()
    {
        $this->processed= array();
    }

    public function setJava($java)
    {
        $this->java= $java;
    }

    public function setClasspath($classpath)
    {
        $this->classpath= $classpath;
    }

    public function setLogLevel($logLevel)
    {
        $this->logLevel = $logLevel;
    }
    
    public function setSpritePngDepth($spritePngDepth)
    {
        $this->spritePngDepth = $spritePngDepth;
    }
    
    public function setSpritePngIe6($spritePngIe6)
    {
        $this->spritePngIe6 = $spritePngIe6;
    }

    public function setCssFileEncoding($cssFileEncoding)
    {
        $this->cssFileEncoding = $cssFileEncoding;
    }
    
    /**
     * @{InheritDoc}
     */
    public function filterLoad(AssetInterface $asset)
    {
        if (strpos($asset->getContent(), '/** sprite') === false) {
          return;
        }

        if (! $this->smartSpritesCacheObsolete($asset)) {
          $this->executeSmartSprites($asset);
        }

        if (preg_match("/$this->spriteFiles/", $asset->getSourcePath()) > 0) {
          $this->filterLoadSpriteFile($asset);
        }
        else {
          $this->filterLoadCssFile($asset);
        }
    }

    private function smartSpritesCacheObsolete($asset)
    {
        $sourceFile= $asset->getSourceRoot().'/'.$asset->getSourcePath();
        $spritedFile= $this->cacheDir."/".$asset->getSourcePath();

        if (file_exists($spritedFile) && filemtime($sourceFile) > filemtime($spritedFile)) {
           return true;
        }
        else {
           return false;
        }
    }

    private function executeSmartSprites($asset)
    {
        $outDir= $this->getOutputRootDirectory($asset);
        @mkdir($outDir, 0770, true);

        $pb = new ProcessBuilder();
        $pb
            ->setEnv("CLASSPATH", join(':', $this->classpath))
            ->add($this->java)
            ->add('-Djava.awt.headless=true')
            ->add('-Djava.ext.dirs=lib')
            ->add('org.carrot2.labs.smartsprites.SmartSprites')
            ->add('--document-root-dir-path')->add($outDir)
            ->add('--output-dir-path')->add($outDir)
	    ->add('--css-file-suffix')->add('')
            ->setWorkingDirectory($asset->getSourceRoot())
            ->add('--root-dir-path')->add('.');

        if (null !== $this->cssFileEncoding) {
            $pb->add('--css-file-encoding')->add($this->cssFileEncoding);
        }

        if ($this->logLevel) {
            $pb->add('--log-level')->add($this->logLevel);
        }
        
        if ($this->spritePngDepth) {
            $pb->add('--sprite-png-depth')->add($this->spritePngDepth);
        }

        if ($this->spritePngIe6) {
            $pb->add('--sprite-png-ie6')->add($this->sprites_ie6);
        }
        
        $pb->add('--css-files');
        foreach ($this->getCssFiles($asset) as $file) {
          $pb->add("./".$file);
        }

        $proc = $pb->getProcess();
        $code = $proc->run();

        if ($code != 0) {
            throw new \RuntimeException($proc->getErrorOutput());
        }

        if (strpos($proc->getOutput(), 'ERROR:') !== false) {
            throw new \RuntimeException($proc->getOutput());
        }
    }
 
    /**
     * The directory where SmartSprites should work while processing $asset.
     *
     * @return string absolute directory.
     */
    private function getOutputRootDirectory($asset)
    {
        return $this->cacheDir.$asset->getSourceRoot();
    }

    /**
     * Where SmartSprites will end up writing the spritified CSS.
     *
     * @return string absolute path to the spritified file.
     */
    private function getOutputCssFile($asset)
    {
        return $this->getOutputRootDirectory($asset).'/'.$asset->getSourcePath();
    }

    /**
     * Where SmartSprites will end up writing the generated sprite image.
     *
     * @return string absolute path to the sprite image file.
     */
    private function getOutputSpriteFile($asset)
    {
        $REGEXP= '{/\*\* sprite:\s*([^\s;]+).*[;\s]sprite-image:\s*url\s*\(\s*[\'"]?([^\'"\s]+)[\'"]?\s*\)}';
        preg_match($REGEXP, $asset->getContent(), $groups);

	$glob= preg_replace('/\${sprite}/', $groups[1], $groups[2]);
	$glob= preg_replace('/\${[^}]+}/', '*', $glob);
	$glob= $this->getOutputRootDirectory($asset).$glob;

	$files= glob($glob);
	$files= array_combine(array_map('filemtime', $files), $files);
	krsort($files);
	return current($files);
    }

    /**
     * Get the collection of files to be spritified together with $asset.
     *
     * @return Collection of file names relative to the asset's source root.
     */ 
    private function getCssFiles($asset)
    {
        //FIXME
        return array('Resources/public/css/common.scss', 'Resources/public/css/sprite-gif.sprite');
    }

    private function filterLoadCssFile($asset)
    {
        $asset->setContent(file_get_contents($this->getOutputCssFile($asset)));
    }

    private function filterLoadSpriteFile($asset)
    {
        $file= $this->getOutputSpriteFile($asset);
        $root= $this->getOutputRootDirectory($asset);
        $target= preg_replace('/\Q$root\E/', '', $file);

        $asset->setContent(file_get_contents($file));
        $asset->setTargetPath($target);
    }

    /**
     * @{InheritDoc}
     */
    public function filterDump(AssetInterface $asset)
    {
    }

    public function __destruct() {
        foreach ($this->processed as $input) {
            unlink($input);
        }
    }
}
