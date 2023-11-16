<?php
/**
 * Generate an `autoload.php` file in the root of the target directory.
 *
 * @see \Composer\Autoload\ClassMapGenerator
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use Composer\Autoload\ClassMapGenerator;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

class Autoload
{

    /** @var Filesystem */
    protected $filesystem;

    protected string $workingDir;

    protected StraussConfig $config;

    /**
     * The files autolaoders of packages that have been copied by Strauss.
     * Keyed by package path.
     *
     * @var array<string, array<string>> $discoveredFilesAutoloaders Array of packagePath => array of relativeFilePaths.
     */
    protected array $discoveredFilesAutoloaders;

    /**
     * Autoload constructor.
     * @param StraussConfig $config
     * @param string $workingDir
     * @param array<string, array<string>> $discoveredFilesAutoloaders
     */
    public function __construct(StraussConfig $config, string $workingDir, array $discoveredFilesAutoloaders)
    {
        $this->config = $config;
        $this->workingDir = $workingDir;
        $this->discoveredFilesAutoloaders = $discoveredFilesAutoloaders;
        $this->filesystem = new Filesystem(new Local($workingDir));
    }

    public function generate(): void
    {
        // Do not overwrite Composer's autoload.php.
        // The correct solution is to add "classmap": ["vendor"] to composer.json, then run composer dump-autoload.
        if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            return;
        }

        if (! $this->config->isClassmapOutput()) {
            return;
        }

        // TODO Don't do this if vendor is the target dir (i.e. in-situ updating).

        $this->generateClassmap();

        $this->generateFilesAutoloader();

        $this->generateAutoloadPhp();
    }

    /**
     * Uses Composer's `ClassMapGenerator::createMap()` to scan the directories for classes and generate the map.
     *
     * createMap() returns the full local path, so we then replace the root of the path with a variable.
     *
     * @see ClassMapGenerator::dump()
     *
     */
    protected function generateClassmap(): string
    {

        // Hyphen used to match WordPress Coding Standards.
        $output_filename = "autoload-classmap.php";

        $targetDirectory = getcwd()
            . DIRECTORY_SEPARATOR
            . ltrim($this->config->getTargetDirectory(), DIRECTORY_SEPARATOR);

        $dirs = array(
            $targetDirectory
        );

        $dirname = '';

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $dirMap = ClassMapGenerator::createMap($dir);

            $dirname = preg_replace('/[^a-z]/i', '', str_replace(getcwd(), '', $dir));

            array_walk(
                $dirMap,
                function (&$filepath, $_class) use ($dir) {
                    $filepath = "\$strauss_src . '"
                        . DIRECTORY_SEPARATOR
                        . ltrim(str_replace($dir, '', $filepath), DIRECTORY_SEPARATOR) . "'";
                }
            );

            ob_start();

            echo "<?php\n\n";
            echo "// {$output_filename} @generated by Strauss\n\n";
            echo "\$strauss_src = dirname(__FILE__);\n\n";
            echo "return array(\n";
            foreach ($dirMap as $class => $file) {
                // Always use `/` in paths.
                $file = str_replace(DIRECTORY_SEPARATOR, '/', $file);
                echo "   '{$class}' => {$file},\n";
            }
            echo ");";

            file_put_contents($dir . $output_filename, ob_get_clean());
        }

        return $dirname;
    }

    protected function generateFilesAutoloader(): void
    {

        // Hyphen used to match WordPress Coding Standards.
        $outputFilename = "autoload-files.php";

        $filesAutoloaders = $this->discoveredFilesAutoloaders;

        if (empty($filesAutoloaders)) {
            return;
        }

        $targetDirectory = getcwd()
            . DIRECTORY_SEPARATOR
            . ltrim($this->config->getTargetDirectory(), DIRECTORY_SEPARATOR);

        $dirname = preg_replace('/[^a-z]/i', '', str_replace(getcwd(), '', $targetDirectory));

        ob_start();

        echo "<?php\n\n";
        echo "// {$outputFilename} @generated by Strauss\n";
        echo "// @see https://github.com/BrianHenryIE/strauss/\n\n";

        foreach ($filesAutoloaders as $packagePath => $files) {
            foreach ($files as $file) {
                $filepath = DIRECTORY_SEPARATOR . $packagePath . DIRECTORY_SEPARATOR . $file;
                $filePathinfo = pathinfo(__DIR__ . $filepath);
                if (!isset($filePathinfo['extension']) || 'php' !== $filePathinfo['extension']) {
                    continue;
                }
                // Always use `/` in paths.
                $filepath = str_replace(DIRECTORY_SEPARATOR, '/', $filepath);
                echo "require_once __DIR__ . '{$filepath}';\n";
            }
        }

        file_put_contents($targetDirectory . $outputFilename, ob_get_clean());
    }

    protected function generateAutoloadPhp(): void
    {

        $autoloadPhp = <<<'EOD'
<?php
// autoload.php @generated by Strauss

if ( file_exists( __DIR__ . '/autoload-classmap.php' ) ) {
    $class_map = include __DIR__ . '/autoload-classmap.php';
    if ( is_array( $class_map ) ) {
        spl_autoload_register(
            function ( $classname ) use ( $class_map ) {
                if ( isset( $class_map[ $classname ] ) && file_exists( $class_map[ $classname ] ) ) {
                    require_once $class_map[ $classname ];
                }
            }
        );
    }
    unset( $class_map, $strauss_src );
}

if ( file_exists( __DIR__ . '/autoload-files.php' ) ) {
    require_once __DIR__ . '/autoload-files.php';
}
EOD;

        $relativeFilepath = $this->config->getTargetDirectory() . 'autoload.php';
        $absoluteFilepath = $this->workingDir . $relativeFilepath;

        file_put_contents($absoluteFilepath, $autoloadPhp);
    }
}
