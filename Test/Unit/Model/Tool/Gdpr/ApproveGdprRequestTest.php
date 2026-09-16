<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\Gdpr\ApproveGdprRequest;
use Magenx\AiMcp\Model\Tool\Gdpr\RequestProjector;
use Magenx\Gdpr\Model\Anonymizer;
use Magenx\Gdpr\Model\DsrRequest;
use Magenx\Gdpr\Model\DsrRequestFactory;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest as RequestResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Approving an erasure request.
 *
 * The most irreversible operation this server can perform: it overwrites a
 * customer's name, email, addresses, phone, order history and newsletter
 * subscription with placeholders, permanently. The write switch and the confirm
 * gate both apply, but the preview they produce names a request id rather than
 * a person — so confirming it tells an agent nothing about whose data is about
 * to go.
 *
 * The email check is what closes that. Everything below is about the cases
 * where it must refuse *before* the Anonymizer is reached, because after it is
 * reached there is nothing to undo.
 *
 * @see ApproveGdprRequest::execute
 */
class ApproveGdprRequestTest extends TestCase
{
    private RequestResource&MockObject $resource;
    private Anonymizer&MockObject $anonymizer;
    private RequestProjector&MockObject $projector;
    private ApproveGdprRequest $tool;

    /** @var array<string, mixed> */
    private array $row = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $request = $this->createMock(DsrRequest::class);
        $request->method('getId')->willReturnCallback(fn () => $this->row['request_id'] ?? null);
        $request->method('getData')->willReturnCallback(
            fn ($key = null, $index = null) => $this->row[$key] ?? null
        );
        $request->method('setData')->willReturnCallback(function ($key, $value = null) use ($request) {
            $this->row[$key] = $value;

            return $request;
        });

        $factory = $this->createMock(DsrRequestFactory::class);
        $factory->method('create')->willReturn($request);

        $this->resource = $this->createMock(RequestResource::class);
        $this->anonymizer = $this->createMock(Anonymizer::class);

        $this->projector = $this->createMock(RequestProjector::class);
        $this->projector->method('toArray')->willReturn([]);

        $clock = $this->createMock(DateTime::class);
        $clock->method('gmtDate')->willReturn('2026-09-16 10:00:00');

        $this->tool = new ApproveGdprRequest(
            $factory,
            $this->resource,
            $this->anonymizer,
            $clock,
            $this->projector
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheRequestsResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magenx_Gdpr::requests', $this->tool->getAclResource());
    }

    /**
     * MCP marks it destructive by default because it writes, and nothing here
     * overrides that — which is correct, and worth pinning so nobody
     * "tidies" it to false later.
     *
     * @return void
     */
    public function testItAdvertisesItselfAsDestructive(): void
    {
        $this->assertTrue($this->tool->getAnnotations()['destructiveHint']);
    }

    /**
     * The guard. A wrong email must stop the call before anything is written.
     *
     * @return void
     */
    public function testAMismatchedEmailRefusesBeforeAnythingIsTouched(): void
    {
        $this->pendingRequestFor(7, 'ada@example.com');
        $this->anonymizer->expects($this->never())->method('anonymizeCustomer');
        $this->resource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('does not match the customer on request');

        $this->tool->execute(['request_id' => 1, 'customer_email' => 'someone.else@example.com']);
    }

    /**
     * @return void
     */
    public function testAMatchingEmailApprovesAndAnonymizes(): void
    {
        $this->pendingRequestFor(7, 'ada@example.com');
        $this->anonymizer->expects($this->once())->method('anonymizeCustomer')->with(7);

        $result = $this->tool->execute([
            'request_id' => 1,
            'customer_email' => 'ada@example.com',
            'note' => 'Verified by phone.',
        ]);

        $this->assertTrue($result['approved']);
        $this->assertSame(7, $result['anonymized_customer_id']);
        $this->assertSame('ada@example.com', $result['anonymized_customer_email']);
        $this->assertSame(DsrRequest::STATUS_APPROVED, $this->row['status']);
        $this->assertSame('Verified by phone.', $this->row['admin_note']);
    }

    /**
     * An address is not case sensitive, but this is a confirmation rather than
     * a search, so nothing else about it is relaxed.
     *
     * @return void
     */
    public function testTheEmailComparisonIgnoresCaseOnly(): void
    {
        $this->pendingRequestFor(7, 'ada@example.com');
        $this->anonymizer->expects($this->once())->method('anonymizeCustomer');

        $this->tool->execute(['request_id' => 1, 'customer_email' => 'Ada@Example.COM']);
    }

    /**
     * @return void
     */
    public function testAPartialEmailIsNotEnough(): void
    {
        $this->pendingRequestFor(7, 'ada@example.com');
        $this->anonymizer->expects($this->never())->method('anonymizeCustomer');

        $this->expectException(LocalizedException::class);

        $this->tool->execute(['request_id' => 1, 'customer_email' => 'ada@example.co']);
    }

    /**
     * Approving twice would re-run an irreversible operation, so a request that
     * has already been resolved is refused on its status alone.
     *
     * @return void
     */
    public function testAnAlreadyResolvedRequestCannotBeApprovedAgain(): void
    {
        $this->row = [
            'request_id' => 1,
            'customer_id' => 7,
            'status' => DsrRequest::STATUS_APPROVED,
        ];
        $this->anonymizer->expects($this->never())->method('anonymizeCustomer');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('already "approved"');

        $this->tool->execute(['request_id' => 1, 'customer_email' => 'ada@example.com']);
    }

    /**
     * A customer who no longer resolves has usually been anonymized already.
     * Running it again would be harmless but the message would be a lie, and
     * the caller needs to know the erasure is done rather than pending.
     *
     * @return void
     */
    public function testACustomerThatNoLongerResolvesIsReportedRatherThanReprocessed(): void
    {
        $this->row = ['request_id' => 1, 'customer_id' => 7, 'status' => DsrRequest::STATUS_PENDING];
        $this->projector->method('emailOf')->willReturn(null);
        $this->anonymizer->expects($this->never())->method('anonymizeCustomer');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('already been anonymized or deleted');

        $this->tool->execute(['request_id' => 1, 'customer_email' => 'ada@example.com']);
    }

    /**
     * @return void
     */
    public function testAnUnknownRequestIsRefused(): void
    {
        $this->row = [];
        $this->anonymizer->expects($this->never())->method('anonymizeCustomer');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No data-subject request exists with request_id 99');

        $this->tool->execute(['request_id' => 99, 'customer_email' => 'ada@example.com']);
    }

    /**
     * The module marks the row resolved before destroying anything, so a
     * failure between the two leaves the data gone and the request visibly
     * closed rather than gone and still pending — which would invite a second
     * call with no record that the first had run. Copied deliberately from the
     * admin controller; pinned so it is not reordered into the obvious-looking
     * arrangement.
     *
     * @return void
     */
    public function testTheRequestIsMarkedResolvedBeforeTheDataIsDestroyed(): void
    {
        $this->pendingRequestFor(7, 'ada@example.com');

        $order = [];
        $this->resource->method('save')->willReturnCallback(function () use (&$order) {
            $order[] = 'save';

            return $this->resource;
        });
        $this->anonymizer->method('anonymizeCustomer')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'anonymize';
        });

        $this->tool->execute(['request_id' => 1, 'customer_email' => 'ada@example.com']);

        $this->assertSame(['save', 'anonymize'], $order);
    }

    /**
     * @param int $customerId
     * @param string $email
     * @return void
     */
    private function pendingRequestFor(int $customerId, string $email): void
    {
        $this->row = [
            'request_id' => 1,
            'customer_id' => $customerId,
            'status' => DsrRequest::STATUS_PENDING,
            'type' => DsrRequest::TYPE_ERASE_DATA,
        ];
        $this->projector->method('emailOf')->willReturn($email);
    }
}
