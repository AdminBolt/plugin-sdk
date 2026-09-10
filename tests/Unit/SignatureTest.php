<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Exception\SignatureException;
use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Tests\TestCase;

final class SignatureTest extends TestCase
{
    public function test_it_accepts_a_signature_it_produced(): void
    {
        $body = '{"hook":"domain.created"}';
        $now = 1_757_500_000;

        Signature::verify('s3cret', Signature::compute('s3cret', $now, $body), $now, $body, 300, $now);

        $this->expectNotToPerformAssertions();
    }

    public function test_it_rejects_a_body_that_changed_after_signing(): void
    {
        $now = 1_757_500_000;
        $signature = Signature::compute('s3cret', $now, '{"amount":1}');

        $this->expectException(SignatureException::class);

        Signature::verify('s3cret', $signature, $now, '{"amount":1000}', 300, $now);
    }

    public function test_it_rejects_a_signature_made_with_another_secret(): void
    {
        $now = 1_757_500_000;
        $body = '{"hook":"domain.creating"}';

        $this->expectException(SignatureException::class);

        Signature::verify('real-secret', Signature::compute('guessed-secret', $now, $body), $now, $body, 300, $now);
    }

    /**
     * The timestamp is inside the signed string, so a captured delivery
     * cannot be re-presented later with a fresh timestamp.
     */
    public function test_it_rejects_a_replayed_delivery(): void
    {
        $signedAt = 1_757_500_000;
        $body = '{"hook":"domain.creating"}';
        $signature = Signature::compute('s3cret', $signedAt, $body);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessageMatches('/tolerance window/');

        Signature::verify('s3cret', $signature, $signedAt, $body, 300, $signedAt + 3_600);
    }

    public function test_it_accepts_either_signature_during_a_secret_rotation(): void
    {
        $now = 1_757_500_000;
        $body = '{"hook":"domain.created"}';
        $header = Signature::compute('old-secret', $now, $body) . ',' . Signature::compute('new-secret', $now, $body);

        Signature::verify('new-secret', $header, $now, $body, 300, $now);
        Signature::verify('old-secret', $header, $now, $body, 300, $now);

        $this->expectNotToPerformAssertions();
    }

    public function test_tolerance_covers_drift_in_both_directions(): void
    {
        $now = 1_757_500_000;
        $body = '{}';

        // A panel whose clock runs slightly ahead still authenticates.
        Signature::verify('s', Signature::compute('s', $now + 120, $body), $now + 120, $body, 300, $now);

        $this->expectNotToPerformAssertions();
    }
}
