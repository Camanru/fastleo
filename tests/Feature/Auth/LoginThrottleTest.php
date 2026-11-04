<?php

declare(strict_types=1);

use Fastleo\Auth\FailedLogins;
use Fastleo\Auth\LoginThrottle;

describe('login throttle', function (): void {
    beforeEach(function (): void {
        $this->throttle = resolve(LoginThrottle::class);
    });

    it('lets five attempts of one account from one address through and refuses the sixth for a minute', function (): void {
        foreach (range(1, LoginThrottle::PER_ACCOUNT_AND_IP) as $attempt) {
            expect($this->throttle->hit('id:1', '10.0.0.1'))->toBeNull();
        }

        expect($this->throttle->hit('id:1', '10.0.0.1'))->toBeInt()->toBeBetween(59, 60)
            ->and($this->throttle->availableIn('id:1', '10.0.0.1'))->toBeInt()
            ->and($this->throttle->availableIn('id:1', '10.0.0.2'))->toBeNull();

        $this->travel(59)->seconds();
        expect($this->throttle->availableIn('id:1', '10.0.0.1'))->not->toBeNull();

        $this->travel(2)->seconds();
        expect($this->throttle->availableIn('id:1', '10.0.0.1'))->toBeNull();
    });

    it('lets twenty attempts from one address through whatever the accounts and refuses the next for a minute', function (): void {
        foreach (range(1, LoginThrottle::PER_IP) as $account) {
            expect($this->throttle->hit('id:'.$account, '10.0.0.1'))->toBeNull();
        }

        expect($this->throttle->hit('id:99', '10.0.0.1'))->toBeInt()
            ->and($this->throttle->availableIn('id:100', '10.0.0.1'))->toBeInt();

        $this->travel(59)->seconds();
        expect($this->throttle->availableIn('id:100', '10.0.0.1'))->not->toBeNull();

        $this->travel(2)->seconds();
        expect($this->throttle->availableIn('id:100', '10.0.0.1'))->toBeNull();
    });

    it('lets fifty attempts at one account from any addresses through and refuses the next for an hour', function (): void {
        foreach (range(1, LoginThrottle::PER_ACCOUNT_HOURLY) as $address) {
            expect($this->throttle->hit('id:1', '10.0.'.intdiv($address, 250).'.'.$address))->toBeNull();
        }

        expect($this->throttle->hit('id:1', '10.1.0.1'))->toBeGreaterThan(3500)
            ->and($this->throttle->availableIn('id:1', '10.2.0.1'))->toBeGreaterThan(3500);

        $this->travel(3599)->seconds();
        expect($this->throttle->availableIn('id:1', '10.2.0.1'))->not->toBeNull();

        $this->travel(2)->seconds();
        expect($this->throttle->availableIn('id:1', '10.2.0.1'))->toBeNull();
    });

    it('keeps the counters of the account and the address apart', function (): void {
        foreach (range(1, LoginThrottle::PER_ACCOUNT_AND_IP + 1) as $attempt) {
            $this->throttle->hit('id:1', '10.0.0.1');
        }

        expect($this->throttle->availableIn('id:2', '10.0.0.1'))->toBeNull()
            ->and($this->throttle->availableIn('id:1', '10.0.0.2'))->toBeNull();
    });
});

describe('failed logins', function (): void {
    it('counts the failures of the last hour and forgets the older ones', function (): void {
        FailedLogins::hit();
        FailedLogins::hit();

        expect(FailedLogins::lastHour())->toBe(2);

        $this->travel(10)->minutes();
        FailedLogins::hit();

        expect(FailedLogins::lastHour())->toBe(3);

        $this->travel(45)->minutes();
        expect(FailedLogins::lastHour())->toBe(3);

        $this->travel(5)->minutes();
        expect(FailedLogins::lastHour())->toBe(1);

        $this->travel(10)->minutes();
        expect(FailedLogins::lastHour())->toBe(0);
    });
});
