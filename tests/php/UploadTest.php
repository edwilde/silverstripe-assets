<?php

namespace SilverStripe\Assets\Tests;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DefaultAssetNameGenerator;
use SilverStripe\Assets\Upload;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

class UploadTest extends SapphireTest
{
    /**
     * {@inheritDoc}
     * @var bool
     */
    protected $usesDatabase = true;

    /**
     * The temporary file path used for upload tests
     * @var string
     */
    protected $tmpFilePath;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        TestAssetStore::activate('UploadTest');

        // Disable is_uploaded_file() in tests
        Upload_Validator::config()->set('use_is_uploaded_file', false);
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();

        $isTmpFile = (strpos($this->tmpFilePath ?? '', __DIR__) !== 0);
        if (file_exists($this->tmpFilePath ?? '') && $isTmpFile) {
            unlink($this->tmpFilePath ?? '');
        }
    }

    public function testUpload()
    {
        // create tmp file
        $tmpFileName = 'UploadTest-testUpload.txt';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent();
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // emulates the $_FILES array
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => 'txt',
            'error' => UPLOAD_ERR_OK,
        ];

        $v = new Upload_Validator();

        // test upload into default folder
        $u1 = new Upload();
        $u1->setValidator($v);
        $u1->loadIntoFile($tmpFile);
        $file1 = $u1->getFile();
        $this->assertEquals(
            'Uploads/UploadTest-testUpload.txt',
            $file1->getFilename()
        );
        $this->assertEquals(
            ASSETS_PATH . '/UploadTest/.protected/Uploads/315ae4c3d4/UploadTest-testUpload.txt',
            TestAssetStore::getLocalPath($file1)
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file1),
            'File upload to standard directory in /assets'
        );

        // test upload into custom folder
        $customFolder = 'UploadTest-testUpload';
        $u2 = new Upload();
        $u2->loadIntoFile($tmpFile, null, $customFolder);
        $file2 = $u2->getFile();
        $this->assertNotNull($file2);
        $this->assertEquals(
            'UploadTest-testUpload/UploadTest-testUpload.txt',
            $file2->getFilename()
        );
        $this->assertEquals(
            ASSETS_PATH . '/UploadTest/.protected/UploadTest-testUpload/315ae4c3d4/UploadTest-testUpload.txt',
            TestAssetStore::getLocalPath($file2)
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file2),
            'File upload to custom directory in /assets'
        );
    }

    public function testAllowedFilesize()
    {
        // create tmp file
        $tmpFileName = 'UploadTest-testUpload.txt';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent();
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // emulates the $_FILES array
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => 'txt',
            'error' => UPLOAD_ERR_OK,
        ];

        // test upload into default folder
        $u1 = new Upload();
        $v = new Upload_Validator();

        $v->setAllowedMaxFileSize(['txt' => 10]);
        $u1->setValidator($v);
        $result = $u1->loadIntoFile($tmpFile);
        $this->assertFalse($result, 'Load failed because size was too big');

        $v->setAllowedMaxFileSize(['[document]' => 10]);
        $u1->setValidator($v);
        $result = $u1->loadIntoFile($tmpFile);
        $this->assertFalse($result, 'Load failed because size was too big');

        $v->setAllowedMaxFileSize(['txt' => 200000]);
        $u1->setValidator($v);
        $result = $u1->loadIntoFile($tmpFile);
        $this->assertTrue($result, 'Load failed with setting max file size');

        // check max file size set by app category
        $tmpFileName = 'UploadTest-testUpload.jpg';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent . $tmpFileContent);

        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'image/jpeg',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => 'jpg',
            'error' => UPLOAD_ERR_OK,
        ];

        $v->setAllowedMaxFileSize(['[image]' => '40k']);
        $u1->setValidator($v);
        $result = $u1->loadIntoFile($tmpFile);
        $this->assertTrue($result, 'Load failed with setting max file size');

        $v->setAllowedMaxFileSize(['[image]' => '1k']);
        $u1->setValidator($v);
        $result = $u1->loadIntoFile($tmpFile);
        $this->assertFalse($result, 'Load failed because size was too big');

        $v->setAllowedMaxFileSize(['[image]' => 1000]);
        $u1->setValidator($v);
        $result = $u1->loadIntoFile($tmpFile);
        $this->assertFalse($result, 'Load failed because size was too big');
    }

    public function testPHPUploadErrors()
    {
        $configMaxFileSizes = ['*' => '1k'];
        Upload_Validator::config()->set('default_max_file_size', $configMaxFileSizes);
        // create tmp file
        $tmpFileName = 'myfile.txt';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent(100);
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // Build file
        $upload = new Upload();
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'tmp_name' => $this->tmpFilePath,
            'size' => filesize($this->tmpFilePath ?? ''),
            'error' => UPLOAD_ERR_OK,
        ];

        // Test ok
        $this->assertTrue($upload->validate($tmpFile));

        // Test zero size file
        $upload->clearErrors();
        $tmpFile['size'] = 0;
        $this->assertFalse($upload->validate($tmpFile));
        $this->assertContains(
            _t('SilverStripe\\Assets\\File.NOFILESIZE', 'Filesize is zero bytes.'),
            $upload->getErrors()
        );

        // Test file too large
        $upload->clearErrors();
        $tmpFile['error'] = UPLOAD_ERR_INI_SIZE;
        $this->assertFalse($upload->validate($tmpFile));
        $this->assertContains(
            _t(
                'SilverStripe\\Assets\\File.TOOLARGE',
                'Filesize is too large, maximum {size} allowed',
                'Argument 1: Filesize (e.g. 1MB)',
                ['size' => '1 KB']
            ),
            $upload->getErrors()
        );

        // Test form size
        $upload->clearErrors();
        $tmpFile['error'] = UPLOAD_ERR_FORM_SIZE;
        $this->assertFalse($upload->validate($tmpFile));
        $this->assertContains(
            _t(
                'SilverStripe\\Assets\\File.TOOLARGE',
                'Filesize is too large, maximum {size} allowed',
                'Argument 1: Filesize (e.g. 1MB)',
                ['size' => '1 KB']
            ),
            $upload->getErrors()
        );

        // Test no file
        $upload->clearErrors();
        $tmpFile['error'] = UPLOAD_ERR_NO_FILE;
        $this->assertFalse($upload->validate($tmpFile));
        $this->assertContains(
            _t('SilverStripe\\Assets\\File.NOVALIDUPLOAD', 'File is not a valid upload'),
            $upload->getErrors()
        );

        // Test no tmp dir
        $upload->clearErrors();
        $tmpFile['error'] = UPLOAD_ERR_NO_TMP_DIR;
        $this->assertFalse($upload->validate($tmpFile));
        $this->assertContains(
            _t('SilverStripe\\Assets\\File.NOVALIDUPLOAD', 'File is not a valid upload'),
            $upload->getErrors()
        );

        // Test can't write error
        $upload->clearErrors();
        $tmpFile['error'] = UPLOAD_ERR_CANT_WRITE;
        $this->assertFalse($upload->validate($tmpFile));
        $this->assertContains(
            _t('SilverStripe\\Assets\\File.NOVALIDUPLOAD', 'File is not a valid upload'),
            $upload->getErrors()
        );

        // Test partial file upload
        $upload->clearErrors();
        $tmpFile['error'] = UPLOAD_ERR_PARTIAL;
        $this->assertFalse($upload->validate($tmpFile));
        $this->assertContains(
            _t('SilverStripe\\Assets\\File.PARTIALUPLOAD', 'File did not finish uploading, please try again'),
            $upload->getErrors()
        );
    }

    public function testGetAllowedMaxFileSize()
    {
        Config::nest();

        // Check the max file size uses the config values
        $configMaxFileSizes = [
            '[image]' => '1k',
            'txt' => 1000
        ];
        Upload_Validator::config()->set('default_max_file_size', $configMaxFileSizes);
        $v = new Upload_Validator();

        $retrievedSize = $v->getAllowedMaxFileSize('[image]');
        $this->assertEquals(
            1024,
            $retrievedSize,
            'Max file size check on default values failed (config category set check)'
        );

        $retrievedSize = $v->getAllowedMaxFileSize('txt');
        $this->assertEquals(
            1000,
            $retrievedSize,
            'Max file size check on default values failed (config extension set check)'
        );

        $this->assertEquals(
            1024,
            $v->getLargestAllowedMaxFileSize(),
            'Unexpected largest max allowed filesize '
        );

        // Check instance values for max file size
        $maxFileSizes = [
            '[document]' => 2000,
            'txt' => '4k'
        ];
        $v = new Upload_Validator();
        $v->setAllowedMaxFileSize($maxFileSizes);

        $retrievedSize = $v->getAllowedMaxFileSize('[document]');
        $this->assertEquals(
            2000,
            $retrievedSize,
            'Max file size check on instance values failed (instance category set check)'
        );

        // Check that the instance values overwrote the default values
        // ie. The max file size will not exist for [image]
        $retrievedSize = $v->getAllowedMaxFileSize('[image]');
        $this->assertFalse($retrievedSize, 'Max file size check on instance values failed (config overridden check)');

        // Check a category that has not been set before
        $retrievedSize = $v->getAllowedMaxFileSize('[archive]');
        $this->assertFalse($retrievedSize, 'Max file size check on instance values failed (category not set check)');

        // Check a file extension that has not been set before
        $retrievedSize = $v->getAllowedMaxFileSize('mp3');
        $this->assertFalse($retrievedSize, 'Max file size check on instance values failed (extension not set check)');

        $retrievedSize = $v->getAllowedMaxFileSize('txt');
        $this->assertEquals(
            4096,
            $retrievedSize,
            'Max file size check on instance values failed (instance extension set check)'
        );

        $this->assertEquals(
            4096,
            $v->getLargestAllowedMaxFileSize(),
            'Unexpected largest max allowed filesize '
        );

        // Check a wildcard max file size against a file with an extension
        $v = new Upload_Validator();
        $v->setAllowedMaxFileSize(2000);

        $retrievedSize = $v->getAllowedMaxFileSize('.jpg');
        $this->assertEquals(
            2000,
            $retrievedSize,
            'Max file size check on instance values failed (wildcard max file size)'
        );

        $this->assertEquals(
            2000,
            $v->getLargestAllowedMaxFileSize(),
            'Unexpected largest max allowed filesize '
        );

        Config::unnest();

        $v = new Upload_Validator();
        $this->assertNull($v->getLargestAllowedMaxFileSize());
    }

    public function testAllowedSizeOnFileWithNoExtension()
    {
        // create tmp file
        $tmpFileName = 'UploadTest-testUpload';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent();
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // emulates the $_FILES array
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => '',
            'error' => UPLOAD_ERR_OK,
        ];

        $v = new Upload_Validator();
        $v->setAllowedMaxFileSize(['' => 10]);

        // test upload into default folder
        $u1 = new Upload();
        $u1->setValidator($v);
        $result = $u1->loadIntoFile($tmpFile);

        $this->assertFalse($result, 'Load failed because size was too big');
    }

    public function testUploadDoesNotAllowUnknownExtension()
    {
        // create tmp file
        $tmpFileName = 'UploadTest-testUpload.php';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent();
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // emulates the $_FILES array
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => 'php',
            'error' => UPLOAD_ERR_OK,
        ];

        $v = new Upload_Validator();
        $v->setAllowedExtensions(['txt']);

        // test upload into default folder
        $u = new Upload();
        $u->setValidator($v);
        $result = $u->loadIntoFile($tmpFile);

        $this->assertFalse($result, 'Load failed because extension was not accepted');
    }

    public function testUploadAcceptsAllowedExtension()
    {
        $textFile = $this->createMockTextFile();
        $upload = $this->getUpload(['txt']);

        // Test upload into default folder
        $upload->loadIntoFile($textFile);
        $file = $upload->getFile();
        $this->assertFileExists(TestAssetStore::getLocalPath($file), 'File upload to custom directory in /assets');
    }

    public function testUploadIntoFileStoresProtectedFilesInsideProtectedFolderWhenCanViewIsInherit()
    {
        if (!class_exists(Versioned::class)) {
            $this->markTestSkipped('This test requires silverstripe/versioned to be installed');
        }
        // Imitate the frontend site
        $this->logOut();
        Versioned::set_stage(Versioned::LIVE);

        // Get some admin user and group info to use for fixtures
        $adminUserID = $this->logInWithPermission('ADMIN');
        $adminUser = Member::get()->byID($adminUserID);
        $adminGroup = $adminUser->Groups()->first();

        // Create a protected folder
        $folder = new Folder([
            'Name' => 'my-secret-folder',
            'Title' => 'my-secret-folder',
            'CanViewType' => 'OnlyTheseUsers',
            'CanEditType' => 'OnlyTheseUsers',
        ]);
        $folder->ViewerGroups()->add($adminGroup);
        $folder->EditorGroups()->add($adminGroup);
        $folder->write();

        $textFile = $this->createMockTextFile();
        $upload = $this->getUpload(['txt']);

        // Push the file into a protected folder
        $file = new File();
        $file->CanViewType = 'Inherit';
        $upload->loadIntoFile($textFile, $file, 'my-secret-folder');

        // Ensure that the file has been written to a protected folder
        $filePath = TestAssetStore::getLocalPath($file);
        $this->assertFileExists($filePath, 'Test file should be uploaded');
        $this->assertStringContainsString('.protected', $filePath, 'Test file path should be protected');
        $this->assertStringContainsString('my-secret-folder', $filePath, 'Test file should be stored under secret folder');
        $this->assertSame(
            AssetStore::VISIBILITY_PROTECTED,
            $file->getVisibility(),
            'Test should be protected since its folder is protected'
        );
    }

    public function testUploadIntoFileStoresPublicFilesInsidePublicFolderWhenCanViewIsInherit()
    {
        if (!class_exists(Versioned::class)) {
            $this->markTestSkipped('This test requires silverstripe/versioned to be installed');
        }
        // Imitate the frontend site
        $this->logOut();
        Versioned::set_stage(Versioned::LIVE);

        // Create a public folder
        $folder = new Folder([
            'Name' => 'my-public-folder',
            'Title' => 'my-public-folder',
            'CanViewType' => 'Anyone',
        ]);
        $folder->write();

        $textFile = $this->createMockTextFile();
        $upload = $this->getUpload(['txt']);

        // Push the file into a protected folder
        $file = new File();
        $file->CanViewType = 'Inherit';
        $upload->loadIntoFile($textFile, $file, 'my-public-folder');

        // Ensure that the file has been written to a protected folder
        $filePath = TestAssetStore::getLocalPath($file);
        $this->assertFileExists($filePath, 'Test file should be uploaded');
        $this->assertStringNotContainsString('.protected', $filePath, 'Test file path should be public');
        $this->assertStringContainsString('my-public-folder', $filePath, 'Test file should be stored under public folder');
        $this->assertSame(
            AssetStore::VISIBILITY_PUBLIC,
            $file->getVisibility(),
            'Test should be public since its folder is public'
        );
    }

    public function testUploadIntoFileStoresProtectedFilesInsidePublicFolderWhenCanViewIsLoggedInUsers()
    {
        if (!class_exists(Versioned::class)) {
            $this->markTestSkipped('This test requires silverstripe/versioned to be installed');
        }
        // Imitate the frontend site
        $this->logOut();
        Versioned::set_stage(Versioned::LIVE);

        // Create a public folder
        $folder = new Folder([
            'Name' => 'my-public-folder',
            'Title' => 'my-public-folder',
            'CanViewType' => 'Anyone',
        ]);
        $folder->write();

        $textFile = $this->createMockTextFile();
        $upload = $this->getUpload(['txt']);

        // Push a protected file into a public folder
        $file = new File();
        $file->CanViewType = 'LoggedInUsers';
        $upload->loadIntoFile($textFile, $file, 'my-public-folder');

        // Ensure that the file has been written to a protected folder
        $filePath = TestAssetStore::getLocalPath($file);
        $this->assertFileExists($filePath, 'Test file should be uploaded');
        $this->assertStringContainsString('.protected', $filePath, 'Test file path should be protected');
        $this->assertStringContainsString('my-public-folder', $filePath, 'Test file should be stored under public folder');
        $this->assertSame(
            AssetStore::VISIBILITY_PROTECTED,
            $file->getVisibility(),
            'Test should be protected since it has CanView = LoggedInUsers'
        );
    }

    public function testUploadIntoFileStoresPublicFilesInsideProtectedFolderWhenCanViewIsAnyone()
    {
        if (!class_exists(Versioned::class)) {
            $this->markTestSkipped('This test requires silverstripe/versioned to be installed');
        }
        // Imitate the frontend site
        $this->logOut();
        Versioned::set_stage(Versioned::LIVE);

        // Create a protected folder
        $folder = new Folder([
            'Name' => 'my-protected-folder',
            'Title' => 'my-protected-folder',
            'CanViewType' => 'Anyone',
        ]);
        $folder->write();

        $textFile = $this->createMockTextFile();
        $upload = $this->getUpload(['txt']);

        // Push a public file into a protected folder
        $file = new File();
        $file->CanViewType = 'Anyone';
        $upload->loadIntoFile($textFile, $file, 'my-protected-folder');

        // Ensure that the file has been written to a protected folder
        $filePath = TestAssetStore::getLocalPath($file);
        $this->assertFileExists($filePath, 'Test file should be uploaded');
        $this->assertStringNotContainsString('.protected', $filePath, 'Test file path should be public');
        $this->assertStringContainsString('my-protected-folder', $filePath, 'Test file should be stored under protected folder');
        $this->assertSame(
            AssetStore::VISIBILITY_PUBLIC,
            $file->getVisibility(),
            'Test should be public since it has CanView = Anyone'
        );
    }

    public function testUploadDeniesNoExtensionFilesIfNoEmptyStringSetForValidatorExtensions()
    {
        // create tmp file
        $tmpFileName = 'UploadTest-testUpload';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent();
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // emulates the $_FILES array
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => '',
            'error' => UPLOAD_ERR_OK,
        ];

        // Upload will work if no special validator is set
        $u1 = new Upload();
        $result1 = $u1->loadIntoFile($tmpFile);
        $this->assertTrue($result1, 'Load failed because extension was not accepted');

        // If a validator limiting extensions is applied, then no-extension files are no longer allowed
        $v = new Upload_Validator();
        $v->setAllowedExtensions(['txt']);

        // test upload into default folder
        $u2 = new Upload();
        $u2->setValidator($v);
        $result2 = $u2->loadIntoFile($tmpFile);

        $this->assertFalse($result2, 'Load failed because extension was not accepted');
        $this->assertEquals(1, count($u2->getErrors() ?? []), 'There is a single error of the file extension');
    }

    public function testUploadTarGzFileTwiceAppendsNumber()
    {
        // create tmp file
        $tmpFileName = 'UploadTest-testUpload.tar.gz';
        $this->tmpFilePath = implode(DIRECTORY_SEPARATOR, [__DIR__, 'UploadTest', $tmpFileName]);

        // emulates the $_FILES array
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => 'tar.gz',
            'error' => UPLOAD_ERR_OK,
        ];

        // test upload into default folder
        $u = new Upload();
        $u->loadIntoFile($tmpFile);
        /** @var File $file */
        $file = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload.tar.gz',
            $file->Name,
            'File has a name without a number because it\'s not a duplicate'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file),
            'File exists'
        );

        $u = new Upload();
        $u->loadIntoFile($tmpFile);
        /** @var File $file2 */
        $file2 = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload-v2.tar.gz',
            $file2->Name,
            'File receives a number attached to the end before the extension'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file2),
            'File exists'
        );
        $this->assertGreaterThan(
            $file->ID,
            $file2->ID,
            'File database record is not the same'
        );

        $u = new Upload();
        $u->loadIntoFile($tmpFile);
        /** @var File $file3 */
        $file3 = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload-v3.tar.gz',
            $file3->Name,
            'File receives a number attached to the end before the extension'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file3),
            'File exists'
        );
        $this->assertGreaterThan(
            $file2->ID,
            $file3->ID,
            'File database record is not the same'
        );
    }

    public function testUploadFileWithNoExtensionTwiceAppendsNumber()
    {
        // create tmp file
        $tmpFileName = 'UploadTest-testUpload';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent();
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // emulates the $_FILES array
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => 'txt',
            'error' => UPLOAD_ERR_OK,
        ];

        $v = new Upload_Validator();
        $v->setAllowedExtensions(['']);

        // test upload into default folder
        $u = new Upload();
        $u->setValidator($v);
        $u->loadIntoFile($tmpFile);
        /** @var File $file */
        $file = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload',
            $file->Name,
            'File is uploaded without extension'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file),
            'File exists'
        );

        $u = new Upload();
        $u->setValidator($v);
        $u->loadIntoFile($tmpFile);
        /** @var File $file2 */
        $file2 = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload-v2',
            $file2->Name,
            'File receives a number attached to the end'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file2),
            'File exists'
        );
        $this->assertGreaterThan(
            $file->ID,
            $file2->ID,
            'File database record is not the same'
        );
    }

    public function testReplaceFile()
    {
        // create tmp file
        $tmpFileName = 'UploadTest-testUpload';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent();
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // emulates the $_FILES array
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => 'txt',
            'error' => UPLOAD_ERR_OK,
        ];

        $v = new Upload_Validator();
        $v->setAllowedExtensions(['']);

        // test upload into default folder
        $u = new Upload();
        $u->setValidator($v);
        $u->loadIntoFile($tmpFile);
        /** @var File $file */
        $file = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload',
            $file->Name,
            'File is uploaded without extension'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file),
            'File exists'
        );

        $u = new Upload();
        $u->setValidator($v);
        $u->setReplaceFile(true);
        $u->loadIntoFile($tmpFile);
        /** @var File $file2 */
        $file2 = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload',
            $file2->Name,
            'File does not receive new name'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file2),
            'File exists'
        );
        $this->assertEquals(
            $file->ID,
            $file2->ID,
            'File database record is the same'
        );
    }

    public function testReplaceFileWithLoadIntoFile()
    {
        // create tmp file
        $tmpFileName = 'UploadTest-testUpload.txt';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent();
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // emulates the $_FILES array
        $tmpFile = [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => 'txt',
            'error' => UPLOAD_ERR_OK,
        ];

        $v = new Upload_Validator();

        // test upload into default folder
        $u = new Upload();
        $u->setValidator($v);
        $u->loadIntoFile($tmpFile);
        /** @var File $file */
        $file = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload.txt',
            $file->Name,
            'File is uploaded without extension'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file),
            'File exists'
        );

        // replace=true
        $u = new Upload();
        $u->setValidator($v);
        $u->setReplaceFile(true);
        $u->loadIntoFile($tmpFile, new File());
        /** @var File $file2 */
        $file2 = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload.txt',
            $file2->Name,
            'File does not receive new name'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file2),
            'File exists'
        );
        $this->assertEquals(
            $file->ID,
            $file2->ID,
            'File database record is the same'
        );

        // replace=false
        $u = new Upload();
        $u->setValidator($v);
        $u->setReplaceFile(false);
        $u->loadIntoFile($tmpFile, new File());
        /** @var File $file3 */
        $file3 = $u->getFile();
        $this->assertEquals(
            'UploadTest-testUpload-v2.txt',
            $file3->Name,
            'File does receive new name'
        );
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file3),
            'File exists'
        );
        $this->assertGreaterThan(
            $file2->ID,
            $file3->ID,
            'File database record is not the same'
        );
    }

    public function testDeleteResampledImagesOnUpload()
    {
        $tmpFileName = 'UploadTest-testUpload.jpg';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;

        $uploadImage = function () use ($tmpFileName) {
            copy(__DIR__ . '/GDTest/images/test_jpg.jpg', $this->tmpFilePath ?? '');

            // emulates the $_FILES array
            $tmpFile = [
                'name' => $tmpFileName,
                'type' => 'text/plain',
                'size' => filesize($this->tmpFilePath ?? ''),
                'tmp_name' => $this->tmpFilePath,
                'extension' => 'jpg',
                'error' => UPLOAD_ERR_OK,
            ];

            $v = new Upload_Validator();

            // test upload into default folder
            $u = new Upload();
            $u->setReplaceFile(true);
            $u->setValidator($v);
            $u->loadIntoFile($tmpFile);
            return $u->getFile();
        };

        // Image upload and generate a resampled image
        /** @var Image $image */
        $image = $uploadImage();
        $resampled = $image->ResizedImage(123, 456);
        $resampledPath = ASSETS_PATH . "/UploadTest/.protected/Uploads/f5c7c2f814/UploadTest-testUpload__{$resampled->getVariant()}.jpg";
        $this->assertFileExists($resampledPath);

        // Re-upload the image, overwriting the original
        // Resampled images should removed when their parent file is overwritten
        $uploadImage();
        $this->assertFileExists($resampledPath);
    }

    public function testFileVersioningWithAnExistingFile()
    {
        $upload = function ($tmpFileName) {
            // create tmp file
            $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
            $tmpFileContent = $this->getTemporaryFileContent();
            file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

            // emulates the $_FILES array
            $tmpFile = [
                'name' => $tmpFileName,
                'type' => 'text/plain',
                'size' => filesize($this->tmpFilePath ?? ''),
                'tmp_name' => $this->tmpFilePath,
                'extension' => 'jpg',
                'error' => UPLOAD_ERR_OK,
            ];

            $v = new Upload_Validator();

            // test upload into default folder
            $u = new Upload();
            $u->setReplaceFile(false);
            $u->setValidator($v);
            $u->loadIntoFile($tmpFile);
            return $u->getFile();
        };

        // test empty file version prefix
        Config::modify()->set(DefaultAssetNameGenerator::class, 'version_prefix', '');

        /** @var Image $file1 */
        $file1 = $upload('UploadTest-IMG001.jpg');
        $this->assertEquals(
            'UploadTest-IMG001.jpg',
            $file1->Name,
            'File does not receive new name'
        );

        /** @var Image $file2 */
        $file2 = $upload('UploadTest-IMG001.jpg');
        $this->assertEquals(
            'UploadTest-IMG002.jpg',
            $file2->Name,
            'File does receive new name'
        );

        /** @var Image $file3 */
        $file3 = $upload('UploadTest-IMG002.jpg');
        $this->assertEquals(
            'UploadTest-IMG003.jpg',
            $file3->Name,
            'File does receive new name'
        );

        /** @var Image $file4 */
        $file4 = $upload('UploadTest-IMG3.jpg');
        $this->assertEquals(
            'UploadTest-IMG3.jpg',
            $file4->Name,
            'File does not receive new name'
        );

        $file1->delete();
        $file2->delete();
        $file3->delete();
        $file4->delete();

        // test '-v' file version prefix
        Config::modify()->set(DefaultAssetNameGenerator::class, 'version_prefix', '-v');

        $file1 = $upload('UploadTest2-IMG001.jpg');
        $this->assertEquals(
            'UploadTest2-IMG001.jpg',
            $file1->Name,
            'File does not receive new name'
        );

        $file2 = $upload('UploadTest2-IMG001.jpg');
        $this->assertEquals(
            'UploadTest2-IMG001-v2.jpg',
            $file2->Name,
            'File does receive new name'
        );

        $file3 = $upload('UploadTest2-IMG001.jpg');
        $this->assertEquals(
            'UploadTest2-IMG001-v3.jpg',
            $file3->Name,
            'File does receive new name'
        );

        $file4 = $upload('UploadTest2-IMG001-v3.jpg');
        $this->assertEquals(
            'UploadTest2-IMG001-v4.jpg',
            $file4->Name,
            'File does receive new name'
        );
    }

    /**
     * Generate some dummy file content
     *
     * @param  int $reps How many zeros to return
     * @return string
     */
    protected function getTemporaryFileContent($reps = 10000)
    {
        return str_repeat('0', $reps ?? 0);
    }

    /**
     * Generates a mock text file and returns an array representing a `$_FILES` entry from a form upload
     *
     * @return array
     */
    protected function createMockTextFile()
    {
        // Create temporary file
        $tmpFileName = 'UploadTest-testUpload.txt';
        $this->tmpFilePath = TEMP_PATH . DIRECTORY_SEPARATOR . $tmpFileName;
        $tmpFileContent = $this->getTemporaryFileContent();
        file_put_contents($this->tmpFilePath ?? '', $tmpFileContent);

        // emulates the $_FILES array
        return [
            'name' => $tmpFileName,
            'type' => 'text/plain',
            'size' => filesize($this->tmpFilePath ?? ''),
            'tmp_name' => $this->tmpFilePath,
            'extension' => 'txt',
            'error' => UPLOAD_ERR_OK,
        ];
    }

    /**
     * Returns an Upload class with a validator attached that accepts the provided extensions
     *
     * @param array $allowedExtensions
     * @return Upload
     */
    protected function getUpload(array $allowedExtensions = [])
    {
        $validator = new Upload_Validator();
        $validator->setAllowedExtensions($allowedExtensions);

        $upload = new Upload();
        $upload->setValidator($validator);

        return $upload;
    }
}
