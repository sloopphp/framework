<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Sloop\Http\Request\UploadedFiles;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;
use Sloop\Validation\Validator;

final class FileRuleTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    /**
     * A one-pixel PNG, small enough to inline and real enough for finfo.
     */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            unlink($path);
        }
    }

    private function tmpFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sloop_file_rule_');
        if ($path === false) {
            self::fail('Could not create a temporary file.');
        }
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return $path;
    }

    private static function upload(string $body, int $error = UPLOAD_ERR_OK, string $clientType = 'application/octet-stream'): UploadedFile
    {
        return new UploadedFile(Stream::create($body), \strlen($body), $error, 'upload.bin', $clientType);
    }

    private static function png(string $clientType = 'image/png'): UploadedFile
    {
        return self::upload((string) base64_decode(self::PNG, true), clientType: $clientType);
    }

    public function testValidatedValueIsTheUploadedFileItself(): void
    {
        $file = self::png();

        $this->assertSame($file, self::valueOf(Rule::file(), $file));
    }

    public function testNonFileIsATypeFailure(): void
    {
        $this->assertSame(['file'], self::failedRules(Rule::file(), 'upload.png'));
    }

    public function testMissingUploadCountsAsEmptyRatherThanAsAFailure(): void
    {
        $result = new Validator(['v' => Rule::file()])
            ->validate(['v' => self::upload('', UPLOAD_ERR_NO_FILE)]);

        $this->assertFalse($result->failed());
        $this->assertNull($result->values()['v']);
    }

    public function testMissingUploadIsCaughtByRequired(): void
    {
        $this->assertSame(
            ['required'],
            self::failedRules(Rule::file()->required(), self::upload('', UPLOAD_ERR_NO_FILE)),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function uploadErrors(): iterable
    {
        yield 'larger than upload_max_filesize' => [UPLOAD_ERR_INI_SIZE];
        yield 'larger than the form limit' => [UPLOAD_ERR_FORM_SIZE];
        yield 'partially written' => [UPLOAD_ERR_PARTIAL];
        yield 'no temporary directory' => [UPLOAD_ERR_NO_TMP_DIR];
        yield 'not written to disk' => [UPLOAD_ERR_CANT_WRITE];
        yield 'stopped by an extension' => [UPLOAD_ERR_EXTENSION];
    }

    #[DataProvider('uploadErrors')]
    public function testUploadErrorsAreReportedApartFromTheTypeFailure(int $error): void
    {
        $this->assertSame(['upload'], self::failedRules(Rule::file(), self::upload('x', $error)));
    }

    public function testUploadErrorIsReportedWithoutAnyRuleBeingDeclared(): void
    {
        $error = self::onlyError(Rule::file(), self::upload('x', UPLOAD_ERR_INI_SIZE));

        $this->assertSame('upload', $error->rule);
        $this->assertSame([], $error->params);
    }

    public function testMaxSizeAcceptsAFileAtTheLimit(): void
    {
        $this->assertSame([], self::failedRules(Rule::file()->maxSize(3), self::upload('abc')));
    }

    public function testMaxSizeRejectsALargerFile(): void
    {
        $error = self::onlyError(Rule::file()->maxSize(3), self::upload('abcd'));

        $this->assertSame('maxSize', $error->rule);
        $this->assertSame(['max' => 3], $error->params);
    }

    public function testMaxSizeAcceptsALimitOfOneByte(): void
    {
        $this->assertSame([], self::failedRules(Rule::file()->maxSize(1), self::upload('a')));
    }

    public function testMaxSizeNeedsAPositiveNumberOfBytes(): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::file()->maxSize(0));

        $this->assertSame('maxSize() needs at least 1 byte, got 0.', $thrown->getMessage());
    }

    public function testMimeTypeIsReadFromTheContentRatherThanFromTheClient(): void
    {
        $lying = self::png(clientType: 'text/plain');

        $this->assertSame([], self::failedRules(Rule::file()->mimeTypes(['image/png']), $lying));
    }

    public function testAClientTypeInTheListDoesNotSaveAFileWhoseContentDiffers(): void
    {
        $text = self::upload('plain text', clientType: 'image/png');

        $error = self::onlyError(Rule::file()->mimeTypes(['image/png']), $text);
        $this->assertSame('mimeTypes', $error->rule);
        $this->assertSame(['types' => ['image/png']], $error->params);
    }

    public function testMimeTypesAcceptsAnyOfTheListedTypes(): void
    {
        $rule = Rule::file()->mimeTypes(['application/pdf', 'image/png']);

        $this->assertSame([], self::failedRules($rule, self::png()));
    }

    public function testMimeTypesNeedsAtLeastOneType(): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::file()->mimeTypes([]));

        $this->assertSame('mimeTypes() needs at least one media type.', $thrown->getMessage());
    }

    public function testTypeIsReadFromTheStartOfAStreamSomethingElseHasAlreadyRead(): void
    {
        $file = self::png();
        // Whatever ran before may have left the stream part-way through; the
        // bytes in the middle of a PNG do not name it.
        $file->getStream()->read(16);

        $this->assertSame([], self::failedRules(Rule::file()->mimeTypes(['image/png']), $file));
    }

    public function testTheStreamIsRewoundSoTheCallerCanReadTheWholeFile(): void
    {
        $file = self::png();
        $rule = Rule::file()->mimeTypes(['image/png']);

        $this->assertSame([], self::failedRules($rule, $file));

        $value = self::valueOf($rule, $file);
        $this->assertInstanceOf(UploadedFileInterface::class, $value);
        $this->assertSame(0, $value->getStream()->tell());
        $this->assertSame(base64_decode(self::PNG, true), (string) $value->getStream());
    }

    public function testTypeStaysUnknownForAStreamThatCannotBeRewound(): void
    {
        $pipe = popen('printf %s "$(: )"', 'r');
        self::assertIsResource($pipe);
        $stream = Stream::create($pipe);
        self::assertFalse($stream->isSeekable(), 'A pipe is expected to be unseekable.');

        $file = new UploadedFile($stream, 0, UPLOAD_ERR_OK, 'upload.bin', 'image/png');

        try {
            $this->assertSame(['mimeTypes'], self::failedRules(Rule::file()->mimeTypes(['image/png']), $file));
            $this->assertSame(0, $file->getStream()->tell(), 'The unread stream should still be at its start.');
        } finally {
            pclose($pipe);
        }
    }

    public function testSizeIsRequiredBeforeAFileCanBeWithinTheLimit(): void
    {
        // PSR-7 allows a null size; the upload cannot be shown to fit, so it
        // does not pass rather than passing by default.
        $file = $this->createStub(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(null);

        $this->assertSame(['maxSize'], self::failedRules(Rule::file()->maxSize(10), $file));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function comparisonsThatCannotHold(): iterable
    {
        yield 'same' => ['same', 'Rule::file() has no same(): two uploads never hold the same value.'];
        yield 'different' => ['different', 'Rule::file() has no different(): two uploads never hold the same value.'];
    }

    #[DataProvider('comparisonsThatCannotHold')]
    public function testComparingWithAnotherFieldIsRefused(string $method, string $message): void
    {
        $declare = match ($method) {
            'same' => static fn (): mixed => Rule::file()->same('other'),
            default => static fn (): mixed => Rule::file()->different('other'),
        };

        $this->assertSame($message, $this->assertThrows(LogicException::class, $declare)->getMessage());
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>}>
     */
    public static function rulesPointingAtAFile(): iterable
    {
        yield 'same' => [Rule::string()->same('avatar')];
        yield 'different' => [Rule::string()->different('avatar')];
    }

    /**
     * @param FieldRule<covariant mixed> $rule
     */
    #[DataProvider('rulesPointingAtAFile')]
    public function testAnotherFieldCannotCompareWithAFileEither(FieldRule $rule): void
    {
        $declare = static fn (): mixed => new Validator(['avatar' => Rule::file(), 'name' => $rule]);

        $this->assertSame(
            'Field "name" compares with "avatar", whose value no two inputs can share.',
            $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage(),
        );
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>}>
     */
    public static function containersHoldingAFile(): iterable
    {
        yield 'a list of files' => [Rule::list(Rule::file())];
        yield 'a shape with a file under a key' => [Rule::shape(['upload' => Rule::file()])];
        yield 'a list of lists of files' => [Rule::list(Rule::list(Rule::file()))];
        yield 'a list of shapes' => [Rule::list(Rule::shape(['upload' => Rule::file()]))];
        yield 'a shape holding a list' => [Rule::shape(['uploads' => Rule::list(Rule::file())])];
        yield 'a shape whose second key is a file' => [Rule::shape(['name' => Rule::string(), 'upload' => Rule::file()])];
    }

    /**
     * @param FieldRule<covariant mixed> $container
     */
    #[DataProvider('containersHoldingAFile')]
    public function testAnotherFieldCannotCompareWithAContainerHoldingAFile(FieldRule $container): void
    {
        $declare = static fn (): mixed => new Validator([
            'uploads' => $container,
            'name'    => Rule::string()->same('uploads'),
        ]);

        $this->assertSame(
            'Field "name" compares with "uploads", whose value no two inputs can share.',
            $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage(),
        );
    }

    /**
     * @param FieldRule<covariant mixed> $container
     */
    #[DataProvider('containersHoldingAFile')]
    public function testAContainerHoldingAFileCannotDeclareSame(FieldRule $container): void
    {
        $declare = static fn (): mixed => new Validator([
            'uploads' => $container->same('name'),
            'name'    => Rule::list(Rule::string()),
        ]);

        $this->assertSame(
            'Field "uploads" compares with "name", but no two inputs can share its own value.',
            $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage(),
        );
    }

    /**
     * @param FieldRule<covariant mixed> $container
     */
    #[DataProvider('containersHoldingAFile')]
    public function testAContainerHoldingAFileCannotDeclareDifferent(FieldRule $container): void
    {
        $declare = static fn (): mixed => new Validator(['name' => Rule::string()])->with('uploads', $container->different('name'));

        $this->assertSame(
            'Field "uploads" compares with "name", but no two inputs can share its own value.',
            $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage(),
        );
    }

    public function testAShapeDefaultNamingAnEmptyUploadForARequiredKeyIsRefused(): void
    {
        $declare = static fn (): mixed => Rule::shape(['f' => Rule::file()->required()])
            ->default(['f' => self::upload('', UPLOAD_ERR_NO_FILE)]);

        $this->assertSame(
            'The default of shape() has no value for "f", which is required.',
            $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage(),
        );
    }

    public function testAShapeDefaultNamingAnEmptyUploadGivesWhatValidatingWouldHaveGiven(): void
    {
        $validator = new Validator(['form' => Rule::shape(['f' => Rule::file()])->default(['f' => self::upload('', UPLOAD_ERR_NO_FILE)])]);

        $this->assertSame(['form' => ['f' => null]], $validator->validate([])->values());
        $this->assertSame(['form' => ['f' => null]], $validator->validate(['form' => ['f' => self::upload('', UPLOAD_ERR_NO_FILE)]])->values());
    }

    public function testAListReportsAFileAfterAnEmptySlotAtItsPositionAmongTheFilesSent(): void
    {
        $input = UploadedFiles::fromGlobals([
            'attachments' => [
                'name'     => ['a.txt', '', 'b.txt'],
                'type'     => ['text/plain', '', 'text/plain'],
                'tmp_name' => [$this->tmpFile('hello'), '', $this->tmpFile((string) base64_decode(self::PNG, true))],
                'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
                'size'     => [5, 0, 70],
            ],
        ]);

        $result = new Validator(['attachments' => Rule::list(Rule::file()->mimeTypes(['text/plain']))])->validate($input);

        $this->assertSame(['attachments.1'], array_keys($result->errors()));
    }

    public function testAShapeReportsAFileAfterAnEmptySlotUnderItsOwnKey(): void
    {
        $input = UploadedFiles::fromGlobals([
            'attachments' => [
                'name'     => ['front' => 'a.txt', 'side' => '', 'back' => 'b.txt'],
                'type'     => ['front' => 'text/plain', 'side' => '', 'back' => 'text/plain'],
                'tmp_name' => ['front' => $this->tmpFile('hello'), 'side' => '', 'back' => $this->tmpFile((string) base64_decode(self::PNG, true))],
                'error'    => ['front' => UPLOAD_ERR_OK, 'side' => UPLOAD_ERR_NO_FILE, 'back' => UPLOAD_ERR_OK],
                'size'     => ['front' => 5, 'side' => 0, 'back' => 70],
            ],
        ]);
        $text  = Rule::file()->mimeTypes(['text/plain']);

        $result = new Validator(['attachments' => Rule::shape(['front' => $text, 'side' => $text, 'back' => $text])])->validate($input);

        $this->assertSame(['attachments.back'], array_keys($result->errors()));
    }

    public function testAnEmptyUploadInsideAListIsANullElementThatTheCountRulesSee(): void
    {
        $empty = self::upload('', UPLOAD_ERR_NO_FILE);

        $passing = new Validator(['p' => Rule::list(Rule::file())->minCount(1)])->validate(['p' => [$empty]]);

        $this->assertSame(['p' => [null]], $passing->values());
        $this->assertSame(['maxCount'], self::failedRules(Rule::list(Rule::file())->maxCount(0), [$empty]));
    }

    public function testAMultipleFileFieldLeftEmptyFailsMinCountOnTheList(): void
    {
        $input = UploadedFiles::fromGlobals([
            'photos' => ['name' => [''], 'type' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]],
        ]);

        $result = new Validator(['photos' => Rule::list(Rule::file())->minCount(1)])->validate($input);

        $this->assertTrue($result->failed());
        $this->assertSame(['photos'], array_keys($result->errors()));
        $this->assertSame('minCount', $result->errors()['photos'][0]->rule);
    }

    public function testASingleFileFieldLeftEmptyFailsRequired(): void
    {
        $input = UploadedFiles::fromGlobals([
            'avatar' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]);

        $result = new Validator(['avatar' => Rule::file()->required()])->validate($input);

        $this->assertTrue($result->failed());
        $this->assertSame(['avatar'], array_keys($result->errors()));
        $this->assertSame('required', $result->errors()['avatar'][0]->rule);
    }

    public function testAListOfStringsIsStillComparedByValue(): void
    {
        $validator = new Validator([
            'tags'  => Rule::list(Rule::string()),
            'again' => Rule::list(Rule::string())->same('tags'),
        ]);

        $this->assertFalse($validator->validate(['tags' => ['a'], 'again' => ['a']])->failed());
        $this->assertTrue($validator->validate(['tags' => ['a'], 'again' => ['b']])->failed());
    }

    public function testAShapeOfStringsIsStillComparedByValue(): void
    {
        $validator = new Validator([
            'name'  => Rule::shape(['first' => Rule::string()]),
            'again' => Rule::shape(['first' => Rule::string()])->same('name'),
        ]);

        $this->assertFalse($validator->validate(['name' => ['first' => 'a'], 'again' => ['first' => 'a']])->failed());
        $this->assertTrue($validator->validate(['name' => ['first' => 'a'], 'again' => ['first' => 'b']])->failed());
    }

    public function testSizeAndTypeAreBothReportedWhenBothFail(): void
    {
        $rule = Rule::file()->maxSize(3)->mimeTypes(['application/pdf']);

        $this->assertSame(['maxSize', 'mimeTypes'], self::failedRules($rule, self::png()));
    }
}
