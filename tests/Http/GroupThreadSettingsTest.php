<?php

namespace RTippin\Messenger\Tests\Http;

use RTippin\Messenger\Broadcasting\ThreadSettingsBroadcast;
use RTippin\Messenger\Contracts\MessengerProvider;
use RTippin\Messenger\Events\ThreadSettingsEvent;
use RTippin\Messenger\Models\Thread;
use RTippin\Messenger\Tests\FeatureTestCase;

class GroupThreadSettingsTest extends FeatureTestCase
{
    private Thread $group;

    private MessengerProvider $tippin;

    private MessengerProvider $doe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tippin = $this->userTippin();

        $this->doe = $this->userDoe();

        $this->group = $this->createGroupThread($this->tippin, $this->doe);
    }

    /** @test */
    public function guest_is_unauthorized()
    {
        $this->getJson(route('api.messenger.threads.settings', [
            'thread' => $this->group->id,
        ]))
            ->assertUnauthorized();
    }

    /** @test */
    public function admin_can_view_group_settings()
    {
        $this->actingAs($this->tippin);

        $this->getJson(route('api.messenger.threads.settings', [
            'thread' => $this->group->id,
        ]))
            ->assertSuccessful()
            ->assertJson([
                'name' => 'First Test Group',
            ]);
    }

    /** @test */
    public function non_admin_forbidden_to_view_group_settings()
    {
        $this->actingAs($this->doe);

        $this->getJson(route('api.messenger.threads.settings', [
            'thread' => $this->group->id,
        ]))
            ->assertForbidden();
    }

    /**
     * @test
     * @dataProvider settingsValidation
     * @param $fieldValue
     */
    public function group_settings_checks_booleans($fieldValue)
    {
        $this->actingAs($this->tippin);

        $this->putJson(route('api.messenger.threads.settings', [
            'thread' => $this->group->id,
        ]), [
            'subject' => 'Passing',
            'messaging' => $fieldValue,
            'add_participants' => $fieldValue,
            'invitations' => $fieldValue,
            'calling' => $fieldValue,
            'knocks' => $fieldValue,
        ])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('subject')
            ->assertJsonValidationErrors([
                'add_participants',
                'invitations',
                'calling',
                'messaging',
                'knocks',
            ]);
    }

    /**
     * @test
     * @dataProvider subjectValidation
     * @param $subject
     */
    public function group_settings_checks_subject($subject)
    {
        $this->actingAs($this->tippin);

        $this->putJson(route('api.messenger.threads.settings', [
            'thread' => $this->group->id,
        ]), [
            'subject' => $subject,
            'messaging' => true,
            'add_participants' => true,
            'invitations' => true,
            'calling' => true,
            'knocks' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject');
    }

    /** @test */
    public function update_group_settings_without_changes_expects_no_events()
    {
        $this->doesntExpectEvents([
            ThreadSettingsBroadcast::class,
            ThreadSettingsEvent::class,
        ]);

        $this->actingAs($this->tippin);

        $this->putJson(route('api.messenger.threads.settings', [
            'thread' => $this->group->id,
        ]), [
            'subject' => 'First Test Group',
            'add_participants' => true,
            'invitations' => true,
            'calling' => true,
            'messaging' => true,
            'knocks' => true,
        ])
            ->assertSuccessful();
    }

    /** @test */
    public function update_group_settings_expects_events_and_name_not_changed()
    {
        $this->expectsEvents([
            ThreadSettingsBroadcast::class,
            ThreadSettingsEvent::class,
        ]);

        $this->actingAs($this->tippin);

        $this->putJson(route('api.messenger.threads.settings', [
            'thread' => $this->group->id,
        ]), [
            'subject' => 'First Test Group',
            'add_participants' => true,
            'invitations' => true,
            'calling' => true,
            'messaging' => false,
            'knocks' => false,
        ])
            ->assertSuccessful()
            ->assertJson([
                'name' => 'First Test Group',
                'messaging' => false,
                'knocks' => false,
            ]);
    }

    /** @test */
    public function update_group_settings_expects_events_and_name_did_change()
    {
        $this->expectsEvents([
            ThreadSettingsBroadcast::class,
            ThreadSettingsEvent::class,
        ]);

        $this->actingAs($this->tippin);

        $this->putJson(route('api.messenger.threads.settings', [
            'thread' => $this->group->id,
        ]), [
            'subject' => 'Second Test Group',
            'add_participants' => true,
            'invitations' => true,
            'calling' => true,
            'messaging' => false,
            'knocks' => false,
        ])
            ->assertSuccessful()
            ->assertJson([
                'name' => 'Second Test Group',
                'messaging' => false,
                'knocks' => false,
            ]);
    }

    public function settingsValidation(): array
    {
        return [
            'Value cannot be an INT' => [2],
            'Value cannot be a string' => ['string'],
            'Value cannot be an array' => [[1, 2]],
            'Value cannot be null' => [null],
        ];
    }

    public function subjectValidation(): array
    {
        return [
            'Value cannot be an INT' => [2],
            'Value cannot be single character' => ['1'],
            'Value must be larger than 2 characters' => ['12'],
            'Value cannot be an array' => [[1, 2]],
            'Value cannot be null' => [null],
            'Value cannot be empty' => [''],
        ];
    }
}
