<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Test\Php;

use Magento\Framework\App\Utility\Files;
use Magento\TestFramework\CodingStandard\Tool\CodeMessDetector;
use Magento\TestFramework\CodingStandard\Tool\CodeSniffer;
use Magento\TestFramework\CodingStandard\Tool\CodeSniffer\Wrapper;
use Magento\TestFramework\CodingStandard\Tool\CopyPasteDetector;
use Magento\TestFramework\CodingStandard\Tool\PhpCompatibility;
use Magento\TestFramework\CodingStandard\Tool\PhpStan;
use Magento\TestFramework\Utility\AddedFiles;
use Magento\TestFramework\Utility\FilesSearch;
use PHPMD\TextUI\Command;

/**
 * Set of tests for static code analysis, e.g. code style, code complexity, copy paste detecting, etc.
 */
class LiveCodeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var string
     */
    protected static $reportDir = '';

    /**
     * @var string
     */
    protected static $pathToSource = '';

    /**
     * Setup basics for all tests
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$pathToSource = BP;
        self::$reportDir = self::$pathToSource . '/dev/tests/static/report';
        if (!is_dir(self::$reportDir)) {
            mkdir(self::$reportDir);
        }
    }

    /**
     * Returns base folder for suite scope
     *
     * @return string
     */
    private static function getBaseFilesFolder()
    {
        return __DIR__;
    }

    /**
     * Returns base directory for whitelisted files
     *
     * @return string
     */
    private static function getChangedFilesBaseDir()
    {
        return __DIR__ . '/..';
    }

    /**
     * returns a multi array with the list of modules with corresponding changes as
     * no. of files changed, insertions and deletions
     *
     * @param string $changeCheckDir
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public static function getChangedCoreModules(string $changeCheckDir = ''): array
    {
        $whitelistFiles = self::getWhitelist(['php', 'graphqls'], '', '', '/_files/whitelist/graphql.txt');

        $affectedModules = [];
        foreach ($whitelistFiles as $whitelistFile) {
            $fileName = substr($whitelistFile, strlen($changeCheckDir));
            $changedModule = explode('/', $fileName);

            $isGraphQlModule = str_ends_with($changedModule[1], 'GraphQl');
            $isGraphQlModuleExists = file_exists($changeCheckDir . '/' . $changedModule[1] . 'GraphQl');

            if ((!$isGraphQlModule && $isGraphQlModuleExists &&
                    (
                        in_array($changedModule[2], ["Controller", "Model", "Block"]) ||
                        (($changedModule[2] == "Ui") && in_array($changedModule[3], ["Component", "DataProvider"]))
                    )
                ) || ($isGraphQlModule)) {
                $gitDiffUnifiedStat = self::getGitDiffUnifiedStat($whitelistFile);
                if (isset($affectedModules[$changedModule[1]])) {
                    $affectedModules[$changedModule[1]]['filesChanged'] += $gitDiffUnifiedStat['filesChanged'];
                    $affectedModules[$changedModule[1]]['insertions'] += $gitDiffUnifiedStat['insertions'];
                    $affectedModules[$changedModule[1]]['deletions'] += $gitDiffUnifiedStat['deletions'];
                    $affectedModules[$changedModule[1]]['paramsChanged'] += $gitDiffUnifiedStat['paramsChanged'];
                } else {
                    $affectedModules[$changedModule[1]] = $gitDiffUnifiedStat;
                }
            }
        }
        return $affectedModules;
    }

    /**
     * Returns the git stats of the file like
     * insertions, deletions and param change if any
     *
     * @param string $filename
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private static function getGitDiffUnifiedStat(string $filename = ''): array
    {
        $shell = new \Magento\Framework\Shell(
            new \Magento\Framework\Shell\CommandRenderer()
        );

        $paramChange = explode(
            PHP_EOL,
            $shell->execute('git diff --stat --unified=0 ' . $filename)
        );

        $fileStatus = array_values(array_filter($paramChange, function($value) {
            return strpos($value, '(+)') || strpos($value, '(-)');
        }, ARRAY_FILTER_USE_BOTH));
        $paramChange = array_filter($paramChange, function($value) {
            return ((str_starts_with(trim($value), '+')) ||
                    (str_starts_with(trim($value), '-'))) &&
                (strpos($value, '@param'));
        }, ARRAY_FILTER_USE_BOTH);

        $insertions = 0;
        $deletions = 0;
        $filesChanged = 0;
        if ($fileStatus) {
            $fileChanges = explode(",", $fileStatus[0]);
            $filesChanged = (isset($fileChanges[0])) ? intval($fileChanges[0]) : 0;
            $insertions = (isset($fileChanges[1])) ? intval($fileChanges[1]) : 0;
            $deletions = (isset($fileChanges[2])) ? intval($fileChanges[2]) : 0;
        }

        return [
            'filesChanged' => $filesChanged,
            'insertions' => $insertions,
            'deletions' => $deletions,
            'paramsChanged' => sizeof($paramChange)
        ];
    }

    /**
     * Returns whitelist based on blacklist and git changed files
     *
     * @param array $fileTypes
     * @param string $changedFilesBaseDir
     * @param string $baseFilesFolder
     * @param string $whitelistFile
     * @return array
     */
    public static function getWhitelist(
        $fileTypes = ['php'],
        $changedFilesBaseDir = '',
        $baseFilesFolder = '',
        $whitelistFile = '/_files/whitelist/common.txt'
    ) {
        $changedFiles = self::getChangedFilesList($changedFilesBaseDir);
        if (empty($changedFiles)) {
            return [];
        }

        $globPatternsFolder = ('' !== $baseFilesFolder) ? $baseFilesFolder : self::getBaseFilesFolder();
        try {
            $directoriesToCheck = Files::init()->readLists($globPatternsFolder . $whitelistFile);
        } catch (\Exception $e) {
            // no directories matched white list
            return [];
        }
        $targetFiles = self::filterFiles($changedFiles, $fileTypes, $directoriesToCheck);
        return $targetFiles;
    }

    /**
     * This method loads list of changed files.
     *
     * List may be generated by:
     *  - dev/tests/static/get_github_changes.php utility (allow to generate diffs between branches),
     *  - CLI command "git diff --name-only > dev/tests/static/testsuite/Magento/Test/_files/changed_files_local.txt",
     *
     * If no generated changed files list found "git diff" will be used to find not committed changed
     * (tests should be invoked from target gir repo).
     *
     * Note: "static" modifier used for compatibility with legacy implementation of self::getWhitelist method
     *
     * @param string $changedFilesBaseDir Base dir with previously generated list files
     * @return string[] List of changed files
     */
    private static function getChangedFilesList($changedFilesBaseDir)
    {
        return FilesSearch::getFilesFromListFile(
            $changedFilesBaseDir ?: self::getChangedFilesBaseDir(),
            'changed_files*',
            function () {
                // if no list files, probably, this is the dev environment
                // phpcs:disable Generic.PHP.NoSilencedErrors,Magento2.Security.InsecureFunction
                @exec('git diff --name-only', $changedFiles);
                @exec('git diff --cached --name-only', $addedFiles);
                // phpcs:enable
                $changedFiles = array_unique(array_merge($changedFiles, $addedFiles));
                return $changedFiles;
            }
        );
    }

    /**
     * Filter list of files.
     *
     * File removed from list:
     *  - if it not exists,
     *  - if allowed types are specified and file has another type (extension),
     *  - if allowed directories specified and file not located in one of them.
     *
     * Note: "static" modifier used for compatibility with legacy implementation of self::getWhitelist method
     *
     * @param string[] $files List of file paths to filter
     * @param string[] $allowedFileTypes List of allowed file extensions (pass empty array to allow all)
     * @param string[] $allowedDirectories List of allowed directories (pass empty array to allow all)
     * @return string[] Filtered file paths
     */
    private static function filterFiles(array $files, array $allowedFileTypes, array $allowedDirectories)
    {
        if (empty($allowedFileTypes)) {
            $fileHasAllowedType = function () {
                return true;
            };
        } else {
            $fileHasAllowedType = function ($file) use ($allowedFileTypes) {
                return in_array(pathinfo($file, PATHINFO_EXTENSION), $allowedFileTypes);
            };
        }

        if (empty($allowedDirectories)) {
            $fileIsInAllowedDirectory = function () {
                return true;
            };
        } else {
            $allowedDirectories = array_map('realpath', $allowedDirectories);
            usort(
                $allowedDirectories,
                function ($dir1, $dir2) {
                    return strlen($dir1) - strlen($dir2);
                }
            );
            $fileIsInAllowedDirectory = function ($file) use ($allowedDirectories) {
                foreach ($allowedDirectories as $directory) {
                    if (strpos($file, $directory) === 0) {
                        return true;
                    }
                }
                return false;
            };
        }

        $filtered = array_filter(
            $files,
            function ($file) use ($fileHasAllowedType, $fileIsInAllowedDirectory) {
                $file = realpath($file);
                if (false === $file) {
                    return false;
                }
                return $fileHasAllowedType($file) && $fileIsInAllowedDirectory($file);
            }
        );

        return $filtered;
    }

    /**
     * Retrieves full list of codebase paths without any files/folders filtered out
     *
     * @return array
     */
    private function getFullWhitelist()
    {
        try {
            return Files::init()->readLists(__DIR__ . '/_files/whitelist/common.txt');
        } catch (\Exception $e) {
            // nothing is whitelisted
            return [];
        }
    }

    /**
     * Returns whether a full scan was requested.
     *
     * This can be set in the `phpunit.xml` used to run these test cases, by setting the constant
     * `TESTCODESTYLE_IS_FULL_SCAN` to `1`, e.g.:
     * ```xml
     * <php>
     *     <!-- TESTCODESTYLE_IS_FULL_SCAN - specify if full scan should be performed for test code style test -->
     *     <const name="TESTCODESTYLE_IS_FULL_SCAN" value="0"/>
     * </php>
     * ```
     *
     * @return bool
     */
    private function isFullScan(): bool
    {
        return defined('TESTCODESTYLE_IS_FULL_SCAN') && TESTCODESTYLE_IS_FULL_SCAN === '1';
    }

    /**
     * Test code quality using phpcs
     */
    public function testCodeStyle()
    {
        $reportFile = self::$reportDir . '/phpcs_report.txt';
        if (!file_exists($reportFile)) {
            touch($reportFile);
        }
        $codeSniffer = new CodeSniffer('Magento', $reportFile, new Wrapper());
        $fileList = $this->isFullScan() ? $this->getFullWhitelist() : self::getWhitelist(['php', 'phtml']);
        $ignoreList = Files::init()->readLists(__DIR__ . '/_files/phpcs/ignorelist/*.txt');
        if ($ignoreList) {
            $ignoreListPattern = sprintf('#(%s)#i', implode('|', $ignoreList));
            $fileList = array_filter(
                $fileList,
                function ($path) use ($ignoreListPattern) {
                    return !preg_match($ignoreListPattern, $path);
                }
            );
        }

        $result = $codeSniffer->run($fileList);
        $report = file_get_contents($reportFile);
        $this->assertEquals(
            0,
            $result,
            "PHP Code Sniffer detected {$result} violation(s): " . PHP_EOL . $report
        );
    }

    /**
     * Test code quality using phpmd
     */
    public function testCodeMess()
    {
        $reportFile = self::$reportDir . '/phpmd_report.txt';
        $codeMessDetector = new CodeMessDetector(realpath(__DIR__ . '/_files/phpmd/ruleset.xml'), $reportFile);

        if (!$codeMessDetector->canRun()) {
            $this->markTestSkipped('PHP Mess Detector is not available.');
        }
        $fileList = self::getWhitelist(['php']);
        $ignoreList = Files::init()->readLists(__DIR__ . '/_files/phpmd/ignorelist/*.txt');
        if ($ignoreList) {
            $ignoreListPattern = sprintf('#(%s)#i', implode('|', $ignoreList));
            $fileList = array_filter(
                $fileList,
                function ($path) use ($ignoreListPattern) {
                    return !preg_match($ignoreListPattern, $path);
                }
            );
        }

        $result = $codeMessDetector->run($fileList);

        $output = "";
        if (file_exists($reportFile)) {
            $output = file_get_contents($reportFile);
        }

        $this->assertEquals(
            Command::EXIT_SUCCESS,
            $result,
            "PHP Code Mess has found error(s):" . PHP_EOL . $output
        );

        // delete empty reports
        if (file_exists($reportFile)) {
            unlink($reportFile);
        }
    }

    /**
     * Test code quality using phpcpd
     */
    public function testCopyPaste()
    {
        $reportFile = self::$reportDir . '/phpcpd_report.xml';
        $copyPasteDetector = new CopyPasteDetector($reportFile);

        if (!$copyPasteDetector->canRun()) {
            $this->markTestSkipped('PHP Copy/Paste Detector is not available.');
        }

        $blackList = [];
        foreach (glob(__DIR__ . '/_files/phpcpd/blacklist/*.txt') as $list) {
            $blackList[] = file($list, FILE_IGNORE_NEW_LINES);
        }
        $blackList = array_merge([], ...$blackList);

        $copyPasteDetector->setBlackList($blackList);

        $result = $copyPasteDetector->run([BP]);

        $output = file_exists($reportFile) ? file_get_contents($reportFile) : '';

        $this->assertTrue(
            $result,
            "PHP Copy/Paste Detector has found error(s):" . PHP_EOL . $output
        );
    }

    /**
     * Tests whitelisted files for strict type declarations.
     */
    public function testStrictTypes()
    {
        $changedFiles = AddedFiles::getAddedFilesList(self::getChangedFilesBaseDir());

        try {
            $blackList = Files::init()->readLists(
                self::getBaseFilesFolder() . '/_files/blacklist/strict_type.txt'
            );
        } catch (\Exception $e) {
            // nothing matched black list
            $blackList = [];
        }

        $toBeTestedFiles = array_diff(
            self::filterFiles($changedFiles, ['php'], []),
            $blackList
        );

        $filesMissingStrictTyping = [];
        foreach ($toBeTestedFiles as $fileName) {
            $file = file_get_contents($fileName);
            if (strstr($file, 'strict_types=1') === false) {
                $filesMissingStrictTyping[] = $fileName;
            }
        }

        $this->assertCount(
            0,
            $filesMissingStrictTyping,
            "Following files are missing strict type declaration:"
            . PHP_EOL
            . implode(PHP_EOL, $filesMissingStrictTyping)
        );
    }

    /**
     * Test code quality using PHPStan
     *
     * @throws \Exception
     */
    public function testPhpStan()
    {
        $reportFile = self::$reportDir . '/phpstan_report.txt';
        $confFile = __DIR__ . '/_files/phpstan/phpstan.neon';

        if (!file_exists($reportFile)) {
            touch($reportFile);
        }

        $fileList = self::getWhitelist(['php']);
        $blackList = Files::init()->readLists(__DIR__ . '/_files/phpstan/blacklist/*.txt');
        if ($blackList) {
            $blackListPattern = sprintf('#(%s)#i', implode('|', $blackList));
            $fileList = array_filter(
                $fileList,
                function ($path) use ($blackListPattern) {
                    return !preg_match($blackListPattern, $path);
                }
            );
        }

        $phpStan = new PhpStan($confFile, $reportFile);
        $exitCode = $phpStan->run($fileList);
        $report = file_get_contents($reportFile);

        $errorMessage = empty($report) ?
            'PHPStan command run failed.' : 'PHPStan detected violation(s):' . PHP_EOL . $report;
        $this->assertEquals(0, $exitCode, $errorMessage);
    }

    /**
     * Tests whitelisted fixtures for reuse other fixtures.
     */
    public function testFixtureReuse()
    {
        $changedFiles =  self::getWhitelist(['php']);
        $toBeTestedFiles = self::filterFiles($changedFiles, ['php'], []);

        $filesWithIncorrectReuse = [];
        foreach ($toBeTestedFiles as $fileName) {
            //check only _files and Fixtures directory
            if (!preg_match('/integration.+\/(_files|Fixtures)/', $fileName)) {
                continue;
            }
            $file = str_replace(["\n", "\r"], '', file_get_contents($fileName));
            if (preg_match('/(?<![\=\s*])\b(require|require_once|include)\b/', $file)) {
                $filesWithIncorrectReuse[] = $fileName;
            }
        }

        $this->assertEquals(
            0,
            count($filesWithIncorrectReuse),
            "The following files incorrectly reuse fixtures:"
            . PHP_EOL
            . implode(PHP_EOL, $filesWithIncorrectReuse)
            . PHP_EOL
            . 'Please use Magento\TestFramework\Workaround\Override\Fixture\Resolver::requireDataFixture'
        );
    }
}
