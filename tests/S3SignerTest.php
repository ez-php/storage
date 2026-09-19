<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\S3Signer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Checks S3Signer against the worked examples in the AWS Signature Version 4
 * documentation ("Signature Calculations for the Authorization Header" and
 * "Authenticating Requests: Using Query Parameters").
 */
#[CoversClass(S3Signer::class)]
final class S3SignerTest extends TestCase
{
    private const KEY = 'AKIAIOSFODNN7EXAMPLE';

    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private const HOST = 'examplebucket.s3.amazonaws.com';

    private const DATE = '20130524T000000Z';

    private const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testSignRequestMatchesTheAwsGetObjectExample(): void
    {
        $signer = new S3Signer(self::KEY, self::SECRET, 'us-east-1');

        $signed = $signer->signRequest(
            'GET',
            '/test.txt',
            '',
            ['range' => 'bytes=0-9'],
            self::EMPTY_PAYLOAD_HASH,
            self::HOST,
            self::DATE,
        );

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $signed['authorization'],
        );
        $this->assertSame(['host', 'range', 'x-amz-content-sha256', 'x-amz-date'], array_keys($signed['headers']));
    }

    public function testPresignQueryMatchesTheAwsPresignedUrlExample(): void
    {
        $signer = new S3Signer(self::KEY, self::SECRET, 'us-east-1');

        $query = $signer->presignQuery(self::HOST, '/test.txt', 86400, self::DATE);

        $this->assertSame(
            'X-Amz-Algorithm=AWS4-HMAC-SHA256'
            . '&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'
            . '&X-Amz-Date=20130524T000000Z'
            . '&X-Amz-Expires=86400'
            . '&X-Amz-SignedHeaders=host'
            . '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $query,
        );
    }

    public function testEncodePathEscapesSegmentsButKeepsSeparators(): void
    {
        $this->assertSame('/my%20folder/file%2Bname.txt', S3Signer::encodePath('/my folder/file+name.txt'));
        $this->assertSame('/a/b~c-d_e.f', S3Signer::encodePath('/a/b~c-d_e.f'));
    }
}
