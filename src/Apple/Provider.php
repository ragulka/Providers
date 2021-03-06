<?php

namespace SocialiteProviders\Apple;

use Firebase\JWT\JWK;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\ProviderInterface;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

class Provider extends AbstractProvider implements ProviderInterface
{
    /**
     * Unique Provider Identifier.
     */
    const IDENTIFIER = 'APPLE';

    private const URL = 'https://appleid.apple.com';

    /**
     * {@inheritdoc}
     */
    protected $scopes = [
        'name',
        'email',
    ];

    /**
     * {@inheritdoc}
     */
    protected $encodingType = PHP_QUERY_RFC3986;

    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(self::URL.'/auth/authorize', $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return self::URL.'/auth/token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getCodeFields($state = null)
    {
        $fields = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUrl,
            'scope'         => $this->formatScopes($this->getScopes(), $this->scopeSeparator),
            'response_type' => 'code',
            'response_mode' => 'form_post',
        ];

        if ($this->usesState()) {
            $fields['state'] = md5($state);
            $fields['nonce'] = Str::uuid().'.'.$state;
        }

        return array_merge($fields, $this->parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessTokenResponse($code)
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            'headers'        => ['Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret)],
            'form_params'    => $this->getTokenFields($code),
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenFields($code)
    {
        return array_merge(parent::getTokenFields($code), [
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        static::verify($token);
        $claims = explode('.', $token)[1];

        return json_decode(base64_decode($claims), true);
    }

    /**
     * Verify Apple jwt.
     *
     * @param string $jwt
     *
     * @return bool
     *
     * @see https://appleid.apple.com/auth/keys
     */
    public static function verify($jwt)
    {
        $signer = new Sha256();

        $token = (new Parser())->parse((string) $jwt);

        if ($token->getClaim('iss') !== self::URL) {
            throw new InvalidStateException('Invalid Issuer', Response::HTTP_UNAUTHORIZED);
        }
        if ($token->isExpired()) {
            throw new InvalidStateException('Token Expired', Response::HTTP_UNAUTHORIZED);
        }

        $data = Cache::remember('socialite:Apple-JWKSet', 5 * 60, function () {
            return json_decode(file_get_contents(self::URL.'/auth/keys'), true);
        });

        $public_keys = JWK::parseKeySet($data);

        $signature_verified = false;

        foreach ($public_keys as $res) {
            $publicKey = openssl_pkey_get_details($res);
            if ($token->verify($signer, $publicKey['key'])) {
                $signature_verified = true;
            }
        }
        if (!$signature_verified) {
            throw new InvalidStateException('Invalid JWT Signature');
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function user()
    {
        //Temporary fix to enable stateless
        $response = $this->getAccessTokenResponse($this->getCode());

        $apple_user_token = $this->getUserByToken(
            $token = Arr::get($response, 'id_token')
        );

        if ($this->usesState()) {
            list($uuid, $state) = explode('.', $apple_user_token['nonce']);
            if (md5($state) == $this->request->input('state')) {
                $this->request->session()->put('state', md5($state));
                $this->request->session()->put('state_verify', $state);
            }
            if ($this->hasInvalidState()) {
                throw new InvalidStateException();
            }
        }

        $user = $this->mapUserToObject($apple_user_token);

        if ($user instanceof User) {
            $user->setAccessTokenResponseBody($response);
        }

        return $user->setToken($token)
            ->setRefreshToken(Arr::get($response, 'refresh_token'))
            ->setExpiresIn(Arr::get($response, 'expires_in'));
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        if (request()->filled('user')) {
            $userRequest = json_decode(request('user'), true);

            if (array_key_exists('name', $userRequest)) {
                $user['name'] = $userRequest['name'];
                $fullName = trim(
                    ($user['name']['firstName'] ?? '')
                    .' '
                    .($user['name']['lastName'] ?? '')
                );
            }
        }

        return (new User())
            ->setRaw($user)
            ->map([
                'id'    => $user['sub'],
                'name'  => $fullName ?? null,
                'email' => $user['email'] ?? null,
            ]);
    }
}
