<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\OutagePhoto;
use App\Services\Mobile\MobileActions;
use App\Services\Mobile\MobileProfile;
use App\Services\Mobile\TicketPresenter;
use App\Services\Mobile\TicketScopes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tickets for the mobile app: outages, 4G tickets, site visits and customer tickets in one list.
 *
 * A ticket id coming from the phone — typed, from a notification or from a link — is never trusted:
 * every request checks that this person may see that ticket (403 otherwise), and every action is
 * authorised again by the policy and the workflow.
 */
class TicketController extends Controller
{
    public function __construct(private readonly TicketPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'scope' => ['nullable', 'string', 'max:30'],
            'module' => ['nullable', 'string', 'max:30'],
            'priority' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $labels = TicketScopes::labels(MobileProfile::role($user));
        $scope = array_key_exists($filters['scope'] ?? '', $labels) ? $filters['scope'] : 'mine';

        $query = TicketScopes::apply(Outage::query(), $user, $scope);
        TicketScopes::module($query, $filters['module'] ?? null);
        TicketScopes::priority($query, $filters['priority'] ?? null);
        TicketScopes::search($query, $filters['search'] ?? null);
        TicketScopes::order($query, $scope);

        $page = $query
            ->with([...TicketPresenter::LIST_RELATIONS, 'assignments' => fn ($q) => $q->where('user_id', $user->id)->whereIn('status', AssignmentStatus::activeValues())])
            ->paginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'scope' => $scope,
            'scope_label' => $labels[$scope],
            'data' => collect($page->items())->map(fn (Outage $outage) => $this->presenter->summary($outage, $user, $outage->assignments->first()))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, Outage $ticket): JsonResponse
    {
        $this->authorizeView($request, $ticket);

        return response()->json(['ticket' => $this->presenter->detail($ticket, $request->user())]);
    }

    /**
     * Run one of the actions the ticket offered this person. The action list and its forms come
     * from the server (see MobileActions); the phone only sends back the answers.
     */
    public function act(Request $request, Outage $ticket, string $action, MobileActions $actions): JsonResponse
    {
        $this->authorizeView($request, $ticket);

        $message = $actions->run($request->user(), $ticket, $action, $request);
        $ticket = Outage::query()->findOrFail($ticket->id);

        return response()->json([
            'message' => $message,
            // After handing a ticket on, the person may no longer see it: the app then goes back to the list.
            'ticket' => MobileProfile::canView($request->user(), $ticket) ? $this->presenter->detail($ticket, $request->user()) : null,
        ]);
    }

    public function attachment(Request $request, OutageAttachment $attachment): StreamedResponse
    {
        $this->authorizeView($request, $attachment->outage);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response($attachment->path, $attachment->name(), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function photo(Request $request, OutagePhoto $photo): StreamedResponse
    {
        $this->authorizeView($request, $photo->outage);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, $photo->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function authorizeView(Request $request, ?Outage $ticket): void
    {
        abort_if($ticket === null, 404);

        if (! MobileProfile::canView($request->user(), $ticket)) {
            throw new AuthorizationException('You don\'t have permission to view this ticket.');
        }
    }
}
