<?php

use App\Domain\Checkout\CheckoutStateMachine;
use App\Enums\CheckoutState;

it('allows pending to authorizing and expired', function () {
    $machine = new CheckoutStateMachine();

    expect($machine->canTransition(CheckoutState::Pending, CheckoutState::Authorizing))->toBeTrue()
        ->and($machine->canTransition(CheckoutState::Pending, CheckoutState::Expired))->toBeTrue()
        ->and($machine->canTransition(CheckoutState::Authorizing, CheckoutState::Complete))->toBeTrue()
        ->and($machine->canTransition(CheckoutState::Complete, CheckoutState::Pending))->toBeFalse();
});

it('rejects illegal transitions', function () {
    $machine = new CheckoutStateMachine();

    $machine->assertCanTransition(CheckoutState::Pending, CheckoutState::Complete);
})->throws(\App\Exceptions\IllegalCheckoutTransitionException::class);
