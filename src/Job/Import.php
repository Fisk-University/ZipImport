<?php
namespace ZipImport\Job;

use CSVImport;
use CSVImport\Source\CsvFile;
use CSVImport\Source\TsvFile;
use Omeka\Entity\Job;
use Laminas\ServiceManager\ServiceLocatorInterface;

class Import extends CSVImport\Job\Import
{
    /**
     * The file store api
     * @var \Omeka\File\Store\StoreInterface;
     */
    protected $store;

    /**
     * The mimetype whitelist your omeka instance is configured with.
     * @var array
     */
    protected $mediaTypes;

    /**
     * The extension whitelist your omeka instance is configured with.
     * @var array
     */
    protected $extensions;

    /**
     * A list of files in the archive
     * @var array
     */
    protected $fileMap = [];

    /**
     * A list of media by identifier
     * @var array
     */
    protected $mediaMap = [];

    /**
     * Track which CSV identifiers had no media in ZIP
     * @var array
     */
    protected $noMediaIdentifiers = [];

    /**
     * Checksum manifest: path => sha256 hash (pre-import)
     * @var array
     */
    protected $checksumManifest = [];

    /**
     * Track skipped files with reasons
     * @var array
     */
    protected $skippedFiles = [];

    /**
     * Inject dependencies.
     *
     * @param Job $job
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(Job $job, ServiceLocatorInterface $serviceLocator)
    {
        parent::__construct($job, $serviceLocator);
        $settings = $serviceLocator->get('Omeka\Settings');
        $this->store = $serviceLocator->get('Omeka\File\Store');
        $this->mediaTypes = $settings->get('media_type_whitelist', []);
        $this->extensions = $settings->get('extension_whitelist', []);
    }

    /**
     * Before performing the csvimport, gather all of the assumed media files in
     * the archive and map them to an assumed row identifier.
     *
     * @return void
     */
    public function perform()
    {
        $this->initalizeFileMap();
        $this->generateChecksumManifest();
        parent::perform();
        $this->validateFileIntegrity();
        $this->logSkippedFilesSummary();
    }

    /**
     * Generate SHA-256 checksums for all files in the file map before import.
     */
    protected function generateChecksumManifest()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $count = 0;
        foreach ($this->fileMap as $identifier => $files) {
            foreach ($files as $file) {
                $path = $file['filepath'] ?? null;
                if ($path && is_file($path)) {
                    $this->checksumManifest[$path] = hash_file('sha256', $path);
                    $count++;
                }
            }
        }
        $logger->info(
            "ZipImport: Checksum manifest generated for $count files before import."
        );
    }

    /**
     * Validate file integrity after import by comparing checksums.
     */
    protected function validateFileIntegrity()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $mismatches = 0;
        $missing = 0;
        foreach ($this->checksumManifest as $path => $expectedHash) {
            if (!is_file($path)) {
                // File was consumed/moved during import — expected behavior
                $missing++;
                continue;
            }
            $currentHash = hash_file('sha256', $path);
            if ($currentHash !== $expectedHash) {
                $mismatches++;
                $this->hasErr = true;
                $logger->err(
                    "ZipImport: File integrity mismatch detected.\n" .
                    "   File: $path\n" .
                    "   Expected SHA-256: $expectedHash\n" .
                    "   Actual SHA-256:   $currentHash"
                );
            }
        }

        $total = count($this->checksumManifest);
        $logger->info(
            "ZipImport: Integrity check complete. " .
            "Total=$total, Consumed=$missing, Verified=" . ($total - $missing) . ", Mismatches=$mismatches"
        );
    }

    /**
     * Log a summary of all skipped files with reasons.
     */
    protected function logSkippedFilesSummary()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        if (empty($this->skippedFiles)) {
            $logger->info("ZipImport: No files were skipped during import.");
            return;
        }

        $byReason = [];
        foreach ($this->skippedFiles as $entry) {
            $reason = $entry['reason'];
            $byReason[$reason][] = $entry['path'];
        }

        $logger->warn(
            "ZipImport: " . count($this->skippedFiles) . " file(s) skipped during import."
        );
        foreach ($byReason as $reason => $paths) {
            $logger->warn(
                "ZipImport: Skipped (" . count($paths) . ") — Reason: $reason\n" .
                "   Files: " . implode(', ', array_map('basename', $paths))
            );
        }
    }

    /**
     * Before bailing on a successful job, go ahead and ensure there were no missed
     * media hits. If there were, fail the job instead and report the skipped
     * media.
     *
     * @return void
     */
    protected function endJob()
    {
        if (!$this->hasErr && !empty($this->fileMap)) {
            $this->hasErr = true;

            foreach ($this->fileMap as $id => $paths) {
                foreach ($paths as $media) {
                    $path = $media['filepath'];
                    $this->logger->err(
                        "Media not imported: $path\n" .
                        "   Reason: ID '$id' not present in csv."
                    );
                }
            }

            $this->logger->err(
                "Some media was present in the archive but " .
                "didn't correspond to a row in the csv. See above for details."
            );
        }

        try {
            $this->writeCSV();
        } catch (\Exception $e) {
            $this->hasErr = true;
            $this->logger->err(
                "Could not modify the CSV\n" . $e->getMessage()
            );
        }

        try {
            $this->cleanTempFiles();
        } catch (\Exception $e) {
            $this->hasErr = true;
            $this->logger->err(
                "Could not clean temp files\n" . $e->getMessage()
            );
        }

        parent::endJob();
    }

    /**
     * Patch the data json with media data from the extracted files, before
     * handing the process back over to CSVImport.
     *
     * @param array $data
     * @return void
     */
    protected function processBatchData(array $data)
    {
        // Modify batch data to include media info
        foreach ($data as $key => &$item) {
            $identifier = $item['o-module-csv-import:resource-identifier'];
            
            // Check if media exists for this identifier
            $mediaFiles = $this->fileMap[$identifier] ?? [];
            
            // Log if no media found in ZIP for this CSV row
            if (empty($mediaFiles)) {
                $this->noMediaIdentifiers[] = $identifier;
                $this->logger->warn('ZipImport: No media found in ZIP for CSV row', [
                    'csv_identifier' => $identifier,
                    'batch_key' => $key
                ]);
            }
            
            $data[$key]['o:media'] = $mediaFiles;
            unset($this->fileMap[$identifier]);
        }

        parent::processBatchData($data);
    }

    /**
     * Hook into csvimport's build import reference to convert the new item ids
     * I have here into a map of identifier => id & media.
     * 
     * This method is called right after the api call that creates or manipulates
     * a set of items.
     *
     * @param \Omeka\Api\Representation\ResourceReference $resourceReference
     *
     * @return array
     */
    protected function buildImportRecordJson($resourceReference)
    {
        try {
            $this->buildMediaMapForResource($resourceReference);
            
            // Check if this item was one with no media expected
            $id = $resourceReference->id();
            $identifier = $this->getItemIdentifier($resourceReference);
            
            if ($identifier && in_array($identifier, $this->noMediaIdentifiers)) {
                $this->logger->info('ZipImport: Item created with NO media (none expected)', [
                    'csv_identifier' => $identifier,
                    'item_id' => $id
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->warn("Resource media lookup failed");
            $this->logger->err($e->getMessage());
        }
        return parent::buildImportRecordJson($resourceReference);
    }

    /**
     * Helper to get item identifier
     */
    protected function getItemIdentifier($resourceReference)
    {
        try {
            $id = $resourceReference->id();
            $identifier = (string) $this->api
                ->read($resourceReference->resourceName(), $id)
                ->getContent()
                ->value('dcterms:identifier')
                ->value();
            return $identifier;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Takes a resource reference and uses it to look up its media and identifier.
     *
     * @param \Omeka\Api\Representation\ResourceReference $resourceReference
     * @return void
     */
    protected function buildMediaMapForResource($resourceReference)
    {
        // Fetch the created id and identifier
        $id = $resourceReference->id();
    
         // todo: Check if dcterms:identifier is always a safe identifier.
        $identifier = $this->getItemIdentifier($resourceReference);

        // Early return. No sense in continuing, there has clearly been an issue.
        if (empty($identifier)) {
            $this->hasErr = true;
            $this->logger->err("ZipImportError: Was unable to determine a suitable csv identifier for resource with id '$id'");
            return;
        }

        // Initialize the media map for this identifier
        $this->mediaMap[$identifier] = ['id' => $id, 'media' => []];
    
        // Fetch the connected media
        $content = $this->api->search('media', ['item_id' => $id])->getContent();
        foreach ($content as $medium) {
            $this->mediaMap[$identifier]['media'][]= $medium->originalUrl();
        }
    }

    /**
     * Traverse the directory that the CSV lives in to pull out all media files,
     * and organize them by their assumed row identifiers.
     *
     * @return void
     */
    protected function initalizeFileMap ()
    {
        $csvPath = $this->getArg('filepath');
        $dir = dirname($csvPath);

        // Loop through all files sibling to the csv
        foreach (scandir($dir) as $file) {
            if (in_array($file, ['.', '..'])) continue;

            $path = $dir . DIRECTORY_SEPARATOR . $file;

            if ($path === $csvPath) continue;

            // If I see a directory add all child media to the filemap
            if (is_dir($path)) {
                foreach (scandir($path) as $child) {
                    $cPath = $path . DIRECTORY_SEPARATOR . $child;

                    if (!is_file($cPath))
                        continue;

                    $this->addToFileMap($file, $cPath);
                }
            } else {
                $this->addToFileMap(pathinfo($path, PATHINFO_FILENAME), $path);
            }
        }
    }

    /**
     * Determine whether or not the provided file path is valid media, and if so
     * add it to the map.
     *
     * @param string $identifier The assumed row identifier
     * @param string $path The file path to validate and add
     *
     * @return void
     */
    protected function addToFileMap($identifier, $path)
    {
        $skipReason = $this->validateMedia($path);
        if ($skipReason !== null) {
            $this->skippedFiles[] = ['path' => $path, 'reason' => $skipReason];
            $this->getServiceLocator()->get('Omeka\Logger')->info(
                "ZipImport: Skipping file.\n" .
                "   File: $path\n" .
                "   Identifier: $identifier\n" .
                "   Reason: $skipReason"
            );
            return;
        }

        if (!array_key_exists($identifier, $this->fileMap)) {
            $this->fileMap[$identifier] = [];
        }

        $this->fileMap[$identifier][] = [
            'o:ingester' => 'tempfile',
            'filepath' => $path,
        ];
    }

    /**
     * Validate whether the provided path is importable media.
     * Returns null if valid, or a string describing the skip reason.
     *
     * @param string $path
     * @return string|null
     */
    protected function validateMedia($path)
    {
        if (!is_file($path)) {
            return "Not a file (path does not exist or is a directory)";
        }

        $mimetype = new \finfo(FILEINFO_MIME_TYPE);
        $mediaType = $mimetype->file($path);
        if (!in_array($mediaType, $this->mediaTypes)) {
            return "MIME type '$mediaType' is not in the allowed media types whitelist";
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (!in_array($ext, $this->extensions)) {
            return "Extension '.$ext' is not in the allowed extensions whitelist";
        }

        return null;
    }

    /**
     * Legacy wrapper for backward compatibility.
     *
     * @param mixed $path
     * @return bool
     */
    protected function isValidMedia($path)
    {
        return $this->validateMedia($path) === null;
    }

    /**
     * Modify the CSV and then write it to storage, updating the comment on this
     * job with a link.
     *
     * @return void
     */
    protected function writeCSV()
    {
        $csvPath = $this->getArg('filepath');
        $filename = htmlspecialchars(basename($csvPath));

        if ($this->source instanceof CsvFile || $this->source instanceof TsvFile) {
            $params = $this->source->getParameters();

            $delimiter = $params['delimiter'];
            $enclosure = $params['enclosure'];
            $escape = $params['escape'];

            $input = fopen($csvPath, 'r');

            $csvPath .= "-mod";
            $output = fopen($csvPath, 'w');

            $separator = $this->getArg('multivalue_separator', ',');

            $headerWritten = false;
            while ($line = fgetcsv($input, null, $delimiter, $enclosure, $escape)) {
                if (!$headerWritten) {
                    $line[] = "Media";
                    $line[] = "Internal ID";
                    $headerWritten = true;
                } else {
                    $identity = $line[$this->getArg('identifier_column')] ?? null;
                    $media = $this->mediaMap[$identity]['media'] ?? [];
                    $id = $this->mediaMap[$identity]['id'] ?? '';
                    $line[] = empty($identity) ? '' : implode($separator, $media);
                    $line[] = $id;
                }
                fputcsv($output, $line, $delimiter, $enclosure, $escape);
            }
        } else {
            $this->logger->warn("Only CSV and TSVs can be modified by ZipImport. No changes have been made to the provided document.");
        }

        $random = microtime();

        $relativePath = "uploads/zipimport/$random/$filename";

        $this->store->put($csvPath, $relativePath);
        $url = $this->store->getUri($relativePath);
        $this->appendToComment("<a href='$url' download='$filename'>Updated CSV</a>");
    }

    /**
     * Append something to the job comment. Only effective before the job ends.
     * @param string $append
     * @param string $delim
     * @return void
     */
    protected function appendToComment($append, $delim = '<br/>')
    {
        $args = $this->job->getArgs();

        $comment = $args['comment'] ?? '';
        if (strlen($comment) > 0)
            $comment .= $delim;
        $comment .= $append;

        $args['comment'] = $comment;
        $this->job->setArgs($args);
    }

    /**
     * Deletes the temp files from the zip archive.
     * @return void
     */
    protected function cleanTempFiles()
    {
        $path = $this->getArg('temppath');
        $this->rmTemp($path);
    }

    /**
     * This is a recursive rmdir / rmfile combination that does a depth-first
     * traversal of the directory structure, deleting everything within.
     *
     * @param string $path
     * @return void
     */
    protected function rmTemp($path)
    {
        $tempDir = $this->getServiceLocator()->get('Config')['temp_dir'];
        if (!str_starts_with($path, $tempDir)) {
            // bail
            $this->logger->err("Failed to clean up temp files.");
            return;
        }

        // Handle file path
        if (!is_dir($path)) {
            unlink($path);
            return;
        }

        // Handle directory path
        // First delete children
        $dir = opendir($path);
        while(false !== ( $file = readdir($dir)) ) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            // Recurse.
            $this->rmTemp($path . DIRECTORY_SEPARATOR . $file);
        }
        closedir($dir);

        // Then delete dir.
        rmdir($path);
    }
}