<?php

declare(strict_types=1);

namespace MunicipioClone\Export;

use WpService\WpService;

/**
 * Provides built-in and filterable washer definitions.
 */
class WasherRegistry
{
    public function __construct(private WpService $wpService)
    {
    }

    /**
     * @return WasherRule[]
     */
    public function all(): array
    {
        $rules = [
            new WasherRule('/(^|_)users$/', 'exclude'),
            new WasherRule('/(^|_)usermeta$/', 'exclude'),
            new WasherRule('/(^|_)comments$/', 'fake', '/comment_author_email$/', 'email'),
            new WasherRule('/(^|_)comments$/', 'fake', '/comment_author_IP$/', 'ip'),
            new WasherRule('/(^|_)comments$/', 'fake', '/comment_author$/', 'name'),
            new WasherRule('/(^|_)wc_customer_lookup$/', 'fake', '/email$/', 'email'),
            new WasherRule('/(^|_)wc_customer_lookup$/', 'fake', '/(first_name|last_name|username)$/', 'name'),
            new WasherRule('/(^|_)wc_customer_lookup$/', 'fake', '/(city|state|postcode|country)$/', 'address'),
            new WasherRule('/(^|_)postmeta$/', 'fake', '/meta_value$/', 'email', 'meta_key', ['_billing_email', '_shipping_email']),
            new WasherRule('/(^|_)postmeta$/', 'fake', '/meta_value$/', 'name', 'meta_key', ['_billing_first_name', '_billing_last_name', '_shipping_first_name', '_shipping_last_name']),
            new WasherRule('/(^|_)postmeta$/', 'fake', '/meta_value$/', 'phone', 'meta_key', ['_billing_phone']),
            new WasherRule('/(^|_)postmeta$/', 'fake', '/meta_value$/', 'address', 'meta_key', ['_billing_address_1', '_billing_address_2', '_billing_city', '_billing_postcode', '_shipping_address_1', '_shipping_address_2', '_shipping_city', '_shipping_postcode']),
            new WasherRule('/(^|_)(gf_entry|gf_entry_meta|gf_entry_notes|flamingo_.*)$/', 'exclude'),
        ];

        /** @var WasherRule[] $rules */
        $rules = $this->wpService->applyFilters('municipio_clone_washers', $rules);

        return $rules;
    }
}
