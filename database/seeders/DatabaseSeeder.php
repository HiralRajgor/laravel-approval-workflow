<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── One user per role ───────────────────────────────────────────────
        $users = [
            ['name' => 'Alice Author',    'email' => 'author@example.com',    'role' => UserRole::AUTHOR],
            ['name' => 'Rex Reviewer',    'email' => 'reviewer@example.com',  'role' => UserRole::REVIEWER],
            ['name' => 'Anna Approver',   'email' => 'approver@example.com',  'role' => UserRole::APPROVER],
            ['name' => 'Pete Publisher',  'email' => 'publisher@example.com', 'role' => UserRole::PUBLISHER],
            ['name' => 'Adam Admin',      'email' => 'admin@example.com',     'role' => UserRole::ADMIN],
        ];

        $created = [];
        foreach ($users as $data) {
            $created[$data['role']->value] = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make('password'),
                'role'     => $data['role'],
            ]);
        }

        $author = $created[UserRole::AUTHOR->value];

        // ── Sample documents at various stages ─────────────────────────────
        $samples = [
            ['title' => 'Q4 Financial Report',         'status' => DocumentStatus::DRAFT],
            ['title' => 'HSE Policy Update',            'status' => DocumentStatus::PENDING],
            ['title' => 'Vendor Onboarding SOP',        'status' => DocumentStatus::IN_REVIEW],
            ['title' => 'Port Operations Manual v2',    'status' => DocumentStatus::APPROVED],
            ['title' => 'IT Security Baseline',         'status' => DocumentStatus::PUBLISHED],
            ['title' => 'Leave Policy Amendment',       'status' => DocumentStatus::REJECTED],
        ];

        foreach ($samples as $data) {
            $doc = Document::create([
                'title'     => $data['title'],
                'body'      => "This is the body content of \"{$data['title']}\".\n\nLorem ipsum dolor sit amet.",
                'status'    => $data['status'],
                'author_id' => $author->id,
            ]);

            $doc->logAuditEvent('created', ['seeded' => true], $author->id);
        }

        $this->command->info('✅ Seeded 5 users (one per role) and 6 sample documents.');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            array_map(fn ($u) => [$u['role']->label(), $u['email'], 'password'], $users)
        );
    }
}
