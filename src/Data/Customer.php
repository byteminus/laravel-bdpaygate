<?php

namespace ByteMinus\BdPayGate\Data;

final class Customer
{
    public function __construct(
        public readonly string $name,
        public readonly string $phone,
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly string $city = 'Dhaka',
        public readonly string $country = 'Bangladesh',
        public readonly ?string $postcode = null,
        public readonly ?string $state = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            phone: (string) ($data['phone'] ?? ''),
            email: isset($data['email']) ? (string) $data['email'] : null,
            address: isset($data['address']) ? (string) $data['address'] : null,
            city: (string) ($data['city'] ?? 'Dhaka'),
            country: (string) ($data['country'] ?? 'Bangladesh'),
            postcode: isset($data['postcode']) ? (string) $data['postcode'] : null,
            state: isset($data['state']) ? (string) $data['state'] : null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'postcode' => $this->postcode,
            'state' => $this->state,
        ];
    }
}
