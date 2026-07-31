<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Controllers;

use Core\Front\Controller;
use Core\Mod\Commerce\Models\Entity;
use Core\Mod\Commerce\Services\PermissionLockedException;
use Core\Mod\Commerce\Services\PermissionMatrixService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handles permission matrix training in development mode.
 */
class MatrixTrainingController extends Controller
{
    public function __construct(
        protected PermissionMatrixService $matrix
    ) {
    }

    /**
     * Process a training decision.
     */
    public function train(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'entity_id' => 'required|exists:commerce_entities,id',
            'key' => 'required|string|max:255',
            'scope' => 'nullable|string|max:255',
            'allow' => 'required|in:0,1',
            'lock' => 'nullable|in:0,1',
            'route' => 'nullable|string|max:2048',
            'return_url' => 'nullable|url',
        ]);

        $entity = Entity::findOrFail($validated['entity_id']);
        $allow = (bool) $validated['allow'];
        $lock = (bool) ($validated['lock'] ?? false);

        try {
            if ($lock) {
                // Lock the permission (cascades to descendants)
                $this->matrix->lock(
                    entity: $entity,
                    key: $validated['key'],
                    allowed: $allow,
                    scope: $validated['scope'] ?? null
                );
            } else {
                // Train the permission (just for this entity)
                $this->matrix->train(
                    entity: $entity,
                    key: $validated['key'],
                    scope: $validated['scope'] ?? null,
                    allow: $allow,
                    route: $validated['route'] ?? null
                );
            }

            // Mark any pending requests as trained
            $this->matrix->markRequestsTrained(
                $entity,
                $validated['key'],
                $validated['scope'] ?? null
            );

            $message = $allow
                ? "Permission '{$validated['key']}' allowed for {$entity->name}"
                : "Permission '{$validated['key']}' denied for {$entity->name}";

            if ($lock) {
                $message .= ' (locked)';
            }

            // Redirect back to the original URL if provided
            if ($returnUrl = $validated['return_url'] ?? null) {
                return redirect($returnUrl)->with('success', $message);
            }

            return redirect()->back()->with('success', $message);

        } catch (PermissionLockedException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Show pending permission requests.
     */
    public function pending(Request $request)
    {
        $entityId = $request->get('entity');
        $entity = $entityId ? Entity::find($entityId) : null;

        $requests = $this->matrix->getPendingRequests($entity);

        return view('commerce::web.matrix.pending', [
            'requests' => $requests,
            'entity' => $entity,
            'entities' => Entity::active()->orderBy('path')->get(),
        ]);
    }

    /**
     * Bulk train permissions.
     */
    public function bulkTrain(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'decisions' => 'required|array',
            'decisions.*.entity_id' => 'required|exists:commerce_entities,id',
            'decisions.*.key' => 'required|string',
            'decisions.*.scope' => 'nullable|string',
            'decisions.*.allow' => 'required|in:0,1',
        ]);

        $trained = 0;
        $errors = [];

        foreach ($validated['decisions'] as $decision) {
            try {
                $entity = Entity::find($decision['entity_id']);

                $this->matrix->train(
                    entity: $entity,
                    key: $decision['key'],
                    scope: $decision['scope'] ?? null,
                    allow: (bool) $decision['allow']
                );

                $this->matrix->markRequestsTrained(
                    $entity,
                    $decision['key'],
                    $decision['scope'] ?? null
                );

                $trained++;

            } catch (PermissionLockedException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors) {
            return redirect()->back()
                ->with('success', "Trained {$trained} permissions")
                ->withErrors($errors);
        }

        return redirect()->back()->with('success', "Trained {$trained} permissions");
    }
}
