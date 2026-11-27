<?php

// The old self-activation flow let anyone who guessed an academic number + mobile set a password. It must stay gone.
it('no longer exposes account activation or the random demo account', function () {
    $this->postJson('/api/v1/account/activate', [])->assertNotFound();
    $this->getJson('/api/v1/demo-account')->assertNotFound();
});
