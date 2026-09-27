<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Request;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Sloop\Http\Request\UploadedFiles;
use Sloop\Tests\Support\ThrowsAssertions;

final class UploadedFilesTest extends TestCase
{
    use ThrowsAssertions;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_uploads_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $paths = glob($this->tmpDir . '/*');
        foreach ($paths === false ? [] : $paths as $path) {
            unlink($path);
        }
        rmdir($this->tmpDir);
    }

    private function tmpFile(string $name, string $contents): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function file(mixed $value): UploadedFileInterface
    {
        $this->assertInstanceOf(UploadedFileInterface::class, $value);

        return $value;
    }

    public function testConvertsASingleFile(): void
    {
        $tmp = $this->tmpFile('a', 'avatar');

        $files = UploadedFiles::fromGlobals([
            'avatar' => ['name' => 'me.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 6],
            'resume' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]);

        $this->assertSame(['avatar', 'resume'], array_keys($files));
        $avatar = $this->file($files['avatar']);
        $this->assertSame('me.png', $avatar->getClientFilename());
        $this->assertSame('image/png', $avatar->getClientMediaType());
        $this->assertSame(UPLOAD_ERR_OK, $avatar->getError());
        $this->assertSame(6, $avatar->getSize());
        $this->assertSame('avatar', (string) $avatar->getStream());
    }

    public function testSplitsAListFieldIntoOneFilePerEntry(): void
    {
        $first  = $this->tmpFile('a', 'one');
        $second = $this->tmpFile('b', 'two');

        $files = UploadedFiles::fromGlobals([
            'photos' => [
                'name'     => ['1.png', '2.png'],
                'type'     => ['image/png', 'image/png'],
                'tmp_name' => [$first, $second],
                'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size'     => [3, 3],
            ],
        ]);

        $this->assertIsArray($files['photos']);
        $this->assertSame([0, 1], array_keys($files['photos']));
        $this->assertSame('1.png', $this->file($files['photos'][0])->getClientFilename());
        $this->assertSame('two', (string) $this->file($files['photos'][1])->getStream());
    }

    public function testFollowsNestingOfAnyDepth(): void
    {
        $tmp = $this->tmpFile('a', 'deep');

        $files = UploadedFiles::fromGlobals([
            'form' => [
                'name'     => ['profile' => ['avatar' => 'me.png']],
                'type'     => ['profile' => ['avatar' => 'image/png']],
                'tmp_name' => ['profile' => ['avatar' => $tmp]],
                'error'    => ['profile' => ['avatar' => UPLOAD_ERR_OK]],
                'size'     => ['profile' => ['avatar' => 4]],
            ],
        ]);

        $this->assertIsArray($files['form']);
        $this->assertIsArray($files['form']['profile']);
        $avatar = $this->file($files['form']['profile']['avatar']);
        $this->assertSame('deep', (string) $avatar->getStream());
        $this->assertSame('me.png', $avatar->getClientFilename());
        $this->assertSame('image/png', $avatar->getClientMediaType());
        $this->assertSame(UPLOAD_ERR_OK, $avatar->getError());
        $this->assertSame(4, $avatar->getSize());
    }

    public function testKeepsANestedKeyNamedLikeASpecKey(): void
    {
        $tmp = $this->tmpFile('a', 'named');

        $files = UploadedFiles::fromGlobals([
            'doc' => [
                'name'     => ['tmp_name' => 'x.txt'],
                'type'     => ['tmp_name' => 'text/plain'],
                'tmp_name' => ['tmp_name' => $tmp],
                'error'    => ['tmp_name' => UPLOAD_ERR_OK],
                'size'     => ['tmp_name' => 5],
            ],
        ]);

        $this->assertIsArray($files['doc']);
        $this->assertSame('x.txt', $this->file($files['doc']['tmp_name'])->getClientFilename());
    }

    public function testCarriesTheUploadErrorOfAFieldLeftEmpty(): void
    {
        $files = UploadedFiles::fromGlobals([
            'avatar' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]);

        $avatar = $this->file($files['avatar']);
        $this->assertSame(UPLOAD_ERR_NO_FILE, $avatar->getError());
        $this->assertSame(0, $avatar->getSize());
    }

    public function testReadsAPartMissingFromTheSpecAsNothingUploaded(): void
    {
        $files = UploadedFiles::fromGlobals(['avatar' => ['name' => ['x' => 'me.png']], 'broken' => 'not a spec']);

        $this->assertSame(['avatar'], array_keys($files));
        $avatar = $this->file($files['avatar']);
        $this->assertSame(UPLOAD_ERR_NO_FILE, $avatar->getError());
        $this->assertSame(0, $avatar->getSize());
        $this->assertNull($avatar->getClientFilename());
        $this->assertNull($avatar->getClientMediaType());
    }

    public function testMovingAFileLeavesNoTemporaryCopyBehind(): void
    {
        $tmp    = $this->tmpFile('a', 'moved');
        $target = $this->tmpDir . '/target';

        $files = UploadedFiles::fromGlobals([
            'avatar' => ['name' => 'me.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 5],
        ]);
        $this->file($files['avatar'])->moveTo($target);

        $this->assertFileDoesNotExist($tmp);
        $this->assertSame('moved', file_get_contents($target));
    }

    public function testAnUnreadableTemporaryFileFailsWhenReadRatherThanReadingAsEmpty(): void
    {
        $missing = $this->tmpDir . '/missing';

        $files  = UploadedFiles::fromGlobals([
            'avatar' => ['name' => 'me.png', 'type' => 'image/png', 'tmp_name' => $missing, 'error' => UPLOAD_ERR_OK, 'size' => 5],
        ]);
        $avatar = $this->file($files['avatar']);

        $this->assertThrows(RuntimeException::class, $avatar->getStream(...));
    }
}
