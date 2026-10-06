<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Log;

use Magenx\AiMcp\Model\Tool\Log\LogEntries;
use Magenx\AiMcp\Model\Tool\Log\LogFiles;
use Magenx\AiMcp\Model\Tool\Log\LogRedactor;
use Magenx\AiMcp\Model\Tool\Log\ReadErrorReport;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * Reading one error report from var/report by id.
 *
 * @see ReadErrorReport
 */
class ReadErrorReportTest extends TestCase
{
    use FakeDirectory;

    /**
     * @param array<string, string> $files
     * @return ReadErrorReport
     */
    private function tool(array $files): ReadErrorReport
    {
        $redactor = new LogRedactor();

        return new ReadErrorReport(
            new LogFiles($this->filesystemHolding($files)),
            new LogEntries($redactor),
            $redactor,
            new Json()
        );
    }

    /**
     * @return void
     */
    public function testAReportIsDecodedTrimmedAndRedacted(): void
    {
        $report = json_encode([
            0 => 'Customer jane@example.com not found',
            1 => "#0 a\n#1 b\n#2 c",
            'url' => '/checkout?token=abc',
            'script_name' => '/index.php',
        ]);

        $result = $this->tool(['report/1234567890' => (string) $report])
            ->execute(['report_id' => 1234567890, 'trace_frames' => 1]);

        $this->assertSame('Customer [email] not found', $result['message']);
        $this->assertSame("#0 a\n#… 2 more frames omitted", $result['trace']);
        $this->assertSame('/checkout?token=[redacted]', $result['url']);
        $this->assertSame('/index.php', $result['script_name']);
    }

    /**
     * @return void
     */
    public function testAReportThatIsNotJsonIsShownAsText(): void
    {
        $result = $this->tool(['report/42' => "a:1:{i:0;s:4:\"Boom\";}"])->execute(['report_id' => '42']);

        $this->assertSame('a:1:{i:0;s:4:"Boom";}', $result['contents']);
    }

    /**
     * Digits only: nothing else can reach the filesystem.
     *
     * @return void
     */
    public function testAnIdThatIsNotDigitsIsRefused(): void
    {
        $this->expectException(LocalizedException::class);

        $this->tool(['report/1' => '{}', 'env.php' => 'x'])->execute(['report_id' => '../env.php']);
    }

    /**
     * @return void
     */
    public function testAMissingReportIsRefused(): void
    {
        $this->expectException(LocalizedException::class);

        $this->tool([])->execute(['report_id' => '999']);
    }
}
