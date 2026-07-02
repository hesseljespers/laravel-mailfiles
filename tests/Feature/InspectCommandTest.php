<?php

declare(strict_types=1);

it('inspects a msg file and prints its subject', function () {
    $this->artisan('mailfiles:inspect', ['path' => fixture('msg/testMSG.msg')])
        ->assertSuccessful()
        ->expectsOutputToContain('MIME registry use cases');
});

it('inspects an eml file', function () {
    $this->artisan('mailfiles:inspect', ['path' => fixture('eml/testRFC822-multipart.eml')])
        ->assertSuccessful()
        ->expectsOutputToContain('Test Multi Part Message');
});

it('fails gracefully for a missing file', function () {
    $this->artisan('mailfiles:inspect', ['path' => '/does/not/exist.eml'])
        ->assertFailed();
});
