<?php

namespace Database\Factories;

use App\Models\Installation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installation>
 */
class InstallationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'name' => $name,
            'path' => config('herd.path').'/'.$name,
            'hidden' => false,
            'status' => 'idle',
            'progress' => 0,
            'current_step' => null,
            'output' => null,
            'last_updated_at' => null,
        ];
    }

    /**
     * Mark the installation as hidden.
     */
    public function hidden(): static
    {
        return $this->state(['hidden' => true]);
    }

    /**
     * Mark the installation as running.
     */
    public function running(): static
    {
        return $this->state([
            'status' => 'running',
            'progress' => 50,
            'current_step' => 'NPM update',
        ]);
    }

    /**
     * Mark the installation as completed.
     */
    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'progress' => 100,
            'current_step' => null,
            'output' => 'Done.',
            'last_updated_at' => now(),
        ]);
    }

    /**
     * Mark the installation as failed.
     */
    public function failed(): static
    {
        return $this->state([
            'status' => 'failed',
            'progress' => 40,
            'current_step' => 'NPM update',
            'output' => 'Error: npm update failed',
            'last_updated_at' => now(),
        ]);
    }
}
