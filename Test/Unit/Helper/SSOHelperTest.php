<?php
/**
 * Bolt magento2 plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2017-2021 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Test\Unit\Helper;

use Bolt\Boltpay\Helper\Api as ApiHelper;
use Bolt\Boltpay\Helper\Config as ConfigHelper;
use Bolt\Boltpay\Helper\SSOHelper;
use Bolt\Boltpay\Model\Request;
use Bolt\Boltpay\Model\Response;
use Bolt\Boltpay\Test\Unit\BoltTestCase;
use Bolt\Boltpay\Test\Unit\TestHelper;
use Exception;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit_Framework_MockObject_MockObject as MockObject;

/**
 * @coversDefaultClass \Bolt\Boltpay\Helper\SSOHelper
 */
class SSOHelperTest extends BoltTestCase
{
    /**
     * @var Context|MockObject
     */
    private $context;

    /**
     * @var ConfigHelper|MockObject
     */
    private $configHelper;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private $storeManager;

    /**
     * @var DataObjectFactory|MockObject
     */
    private $dataObjectFactory;

    /**
     * @var ApiHelper|MockObject
     */
    private $apiHelper;

    /**
     * @var SSOHelper|MockObject
     */
    private $currentMock;

    /**
     * @inheritdoc
     */
    public function setUpInternal()
    {
        $this->context = $this->createMock(Context::class);
        $this->configHelper = $this->createMock(ConfigHelper::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->dataObjectFactory = $this->createMock(DataObjectFactory::class);
        $this->apiHelper = $this->createMock(ApiHelper::class);
        $this->currentMock = $this->getMockBuilder(SSOHelper::class)
            ->setMethods(['getOAuthConfiguration', 'exchangeToken', 'parseAndValidateJWT'])
            ->setConstructorArgs([
                $this->context,
                $this->configHelper,
                $this->storeManager,
                $this->dataObjectFactory,
                $this->apiHelper
            ])
            ->getMock();
    }

    /**
     * @test
     *
     * @covers ::getOAuthConfiguration
     */
    public function getOAuthConfiguration_returnsCorrectValue()
    {
        $store = $this->createMock(Store::class);
        $store->expects(static::once())->method('getId')->willReturn(1);
        $this->storeManager->expects(static::once())->method('getStore')->willReturn($store);
        $this->configHelper->expects(static::once())->method('getPublishableKeyCheckout')->with(1)->willReturn('a.b.lastpart');
        $this->configHelper->expects(static::once())->method('getApiKey')->with(1)->willReturn('test api key');
        $this->configHelper->expects(static::once())->method('getPublicKey')->with(1)->willReturn('test public key');
        $this->assertEquals(
            ['lastpart', 'test api key', 'test public key'],
            TestHelper::invokeMethod($this->currentMock, 'getOAuthConfiguration')
        );
    }

    /**
     * @test
     *
     * @covers ::exchangeToken
     */
    public function exchangeToken_returnsExceptionMessage_ifExceptionIsThrown()
    {
        $this->storeManager->expects(static::once())->method('getStore')->willThrowException(new Exception('test exception'));
        $this->assertEquals('test exception', TestHelper::invokeMethod($this->currentMock, 'exchangeToken', ['', '', '', '']));
    }

    /**
     * @test
     *
     * @covers ::exchangeToken
     *
     * @dataProvider exchangeTokenProvider
     *
     * @param mixed        $responseBody
     * @param mixed|string $expected
     */
    public function exchangeToken_returnsCorrectValue_forAllCases($responseBody, $expected)
    {
        $store = $this->createMock(Store::class);
        $store->expects(static::once())->method('getId')->willReturn(1);
        $this->storeManager->expects(static::once())->method('getStore')->willReturn($store);
        $this->configHelper->expects(static::once())->method('getApiKey')->with(1)->willReturn('test api key');
        $dataObject = $this->createPartialMock(DataObject::class, ['setApiData', 'setDynamicApiUrl', 'setApiKey']);
        $dataObject->expects(static::once())->method('setApiData')->with('grant_type=authorization_code&code=abc&scope=openid&client_id=clientid&client_secret=clientsecret');
        $dataObject->expects(static::once())->method('setDynamicApiUrl')->with('oauth/token');
        $dataObject->expects(static::once())->method('setApiKey')->with('test api key');
        $this->dataObjectFactory->expects(static::once())->method('create')->willReturn($dataObject);
        $request = $this->createMock(Request::class);
        $this->apiHelper->expects(static::once())->method('buildRequest')->with($dataObject)->willReturn($request);
        $response = $this->createPartialMock(Response::class, ['getResponse']);
        $response->expects(static::once())->method('getResponse')->willReturn($responseBody);
        $this->apiHelper->expects(static::once())->method('sendRequest')->with($request, 'application/x-www-form-urlencoded')->willReturn($response);
        $this->assertEquals($expected, TestHelper::invokeMethod($this->currentMock, 'exchangeToken', ['abc', 'openid', 'clientid', 'clientsecret']));
    }

    /**
     * Data provider for {@see exchangeToken_returnsCorrectValue_forAllCases}
     *
     * @return array
     */
    public function exchangeTokenProvider()
    {
        return [
            ['responseBody' => [], 'expected' => 'empty response'],
            [
                'responseBody' => ['access_token' => 'test access token', 'id_token' => 'test id token'],
                'expected'     => ['access_token' => 'test access token', 'id_token' => 'test id token']
            ],
        ];
    }

    /**
     * @test
     *
     * @covers ::parseAndValidateJWT
     *
     * @dataProvider parseAndValidateJWTProvider
     *
     * @param string       $token
     * @param string       $audience
     * @param string       $pubkey
     * @param mixed|string $expected
     */
    public function parseAndValidateJWT_returnsCorrectValue_forAllCases($token, $audience, $pubkey, $expected)
    {
        $this->assertEquals($expected, TestHelper::invokeMethod($this->currentMock, 'parseAndValidateJWT', [$token, $audience, $pubkey]));
    }

    /**
     * Data provider for {@see parseAndValidateJWT_returnsCorrectValue_forAllCases}
     *
     * @return array
     */
    public function parseAndValidateJWTProvider()
    {
        $wrongSigAndPubkeyNoSub = $this->getSignatureAndPublicKey(base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","first_name":"first name","last_name":"last name","email":"t@t.com","email_verified":true}'));
        $wrongSigAndPubkeyNoFirstName = $this->getSignatureAndPublicKey(base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","last_name":"last name","email":"t@t.com","email_verified":true}'));
        $wrongSigAndPubkeyNoLastName = $this->getSignatureAndPublicKey(base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","first_name":"first name","email":"t@t.com","email_verified":true}'));
        $wrongSigAndPubkeyNoEmail = $this->getSignatureAndPublicKey(base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","first_name":"first name","last_name":"last name","email_verified":true}'));
        $wrongSigAndPubkeyNoEmailVerified = $this->getSignatureAndPublicKey(base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","first_name":"first name","last_name":"last name","email":"t@t.com"}'));
        $rightSigAndPubkey = $this->getSignatureAndPublicKey(base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","first_name":"first name","last_name":"last name","email":"t@t.com","email_verified":true}'));
        return [
            [
                'token'    => '',
                'audience' => '',
                'pubkey'   => '',
                'expected' => 'token must have three parts'
            ],
            [
                'token'    => '.' . base64_encode('{}') . '.',
                'audience' => '',
                'pubkey'   => '',
                'expected' => 'exp must be set'
            ],
            [
                'token'    => '.' . base64_encode('{"exp":0}') . '.',
                'audience' => '',
                'pubkey'   => '',
                'expected' => 'expired exp 0'
            ],
            [
                'token'    => '.' . base64_encode('{"exp":2000000000}') . '.',
                'audience' => '',
                'pubkey'   => '',
                'expected' => 'iss must be set'
            ],
            [
                'token'    => '.' . base64_encode('{"exp":2000000000,"iss":"not bolt"}') . '.',
                'audience' => '',
                'pubkey'   => '',
                'expected' => 'incorrect iss not bolt'
            ],
            [
                'token'    => '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com"}') . '.',
                'audience' => '',
                'pubkey'   => '',
                'expected' => 'aud must be set'
            ],
            [
                'token'    => '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"blah"}') . '.',
                'audience' => 'test audience',
                'pubkey'   => '',
                'expected' => 'aud blah does not contain audience test audience'
            ],
            [
                'token'    => base64_encode('{}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx"}') . '.',
                'audience' => 'test audience',
                'pubkey'   => '',
                'expected' => 'alg must be set'
            ],
            [
                'token'    => base64_encode('{"alg":"random"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx"}') . '.',
                'audience' => 'test audience',
                'pubkey'   => '',
                'expected' => 'invalid alg random'
            ],
            [
                'token'    => base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000001,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","first_name":"first name","last_name":"last name","email":"t@t.com","email_verified":true}') . '.' . $rightSigAndPubkey['sig'],
                'audience' => 'test audience',
                'pubkey'   => $rightSigAndPubkey['pubkey'],
                'expected' => 'signature verification failed'
            ],
            [
                'token'    => base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","first_name":"first name","last_name":"last name","email":"t@t.com","email_verified":true}') . '.' . $wrongSigAndPubkeyNoSub['sig'],
                'audience' => 'test audience',
                'pubkey'   => $wrongSigAndPubkeyNoSub['pubkey'],
                'expected' => 'sub must be set'
            ],
            [
                'token'    => base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","last_name":"last name","email":"t@t.com","email_verified":true}') . '.' . $wrongSigAndPubkeyNoFirstName['sig'],
                'audience' => 'test audience',
                'pubkey'   => $wrongSigAndPubkeyNoFirstName['pubkey'],
                'expected' => 'first_name must be set'
            ],
            [
                'token'    => base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","first_name":"first name","email":"t@t.com","email_verified":true}') . '.' . $wrongSigAndPubkeyNoLastName['sig'],
                'audience' => 'test audience',
                'pubkey'   => $wrongSigAndPubkeyNoLastName['pubkey'],
                'expected' => 'last_name must be set'
            ],
            [
                'token'    => base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","first_name":"first name","last_name":"last name","email_verified":true}') . '.' . $wrongSigAndPubkeyNoEmail['sig'],
                'audience' => 'test audience',
                'pubkey'   => $wrongSigAndPubkeyNoEmail['pubkey'],
                'expected' => 'email must be set'
            ],
            [
                'token'    => base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","first_name":"first name","last_name":"last name","email":"t@t.com"}') . '.' . $wrongSigAndPubkeyNoEmailVerified['sig'],
                'audience' => 'test audience',
                'pubkey'   => $wrongSigAndPubkeyNoEmailVerified['pubkey'],
                'expected' => 'email_verified must be set'
            ],
            [
                'token'    => base64_encode('{"alg":"RS256"}') . '.' . base64_encode('{"exp":2000000000,"iss":"https://bolt.com","aud":"xxtest audiencexx","sub":"test sub","first_name":"first name","last_name":"last name","email":"t@t.com","email_verified":true}') . '.' . $rightSigAndPubkey['sig'],
                'audience' => 'test audience',
                'pubkey'   => $rightSigAndPubkey['pubkey'],
                'expected' => [
                    'exp'            => 2000000000,
                    'iss'            => 'https://bolt.com',
                    'aud'            => 'xxtest audiencexx',
                    'sub'            => 'test sub',
                    'first_name'     => 'first name',
                    'last_name'      => 'last name',
                    'email'          => 't@t.com',
                    'email_verified' => true
                ]
            ]
        ];
    }

    private function getSignatureAndPublicKey($data)
    {
        $private_key_res = openssl_pkey_new(array(
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ));
        openssl_sign(base64_encode($data), $signature, $private_key_res, OPENSSL_ALGO_SHA256);
        return [
            'sig'    => base64_encode($signature),
            'pubkey' => openssl_pkey_get_details($private_key_res)['key']
        ];
    }
}