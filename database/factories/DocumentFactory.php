<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title'     => $this->faker->sentence(4),
            'body'      => $this->faker->paragraphs(3, asText: true),
            'status'    => DocumentStatus::DRAFT,
            'author_id' => User::factory()->author(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => DocumentStatus::DRAFT]);
    }

    public function pending(): static
    {
        return $this->state(['status' => DocumentStatus::PENDING]);
    }

    public function inReview(): static
    {
        return $this->state(['status' => DocumentStatus::IN_REVIEW]);
    }

    public function approved(): static
    {
        return $this->state(['status' => DocumentStatus::APPROVED]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => DocumentStatus::REJECTED]);
    }

    public function published(): static
    {
        return $this->state(['status' => DocumentStatus::PUBLISHED]);
    }
}
