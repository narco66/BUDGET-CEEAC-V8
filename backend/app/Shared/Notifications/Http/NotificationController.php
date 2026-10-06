<?php

namespace App\Shared\Notifications\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Audit\AuditService;
use App\Shared\Notifications\NotificationCatalog;
use App\Shared\Notifications\NotificationTargetResolver;
use App\Shared\Notifications\RecapitulatifService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationTargetResolver $cibles) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->validerFiltres($request);
        $page = $this->filtrer($user->notifications()->getQuery(), $request)
            ->latest()
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return response()->json([
            'data' => $page->getCollection()->map(fn (DatabaseNotification $notification) => $this->cibles->carte($user, $notification))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'non_lues' => $user->unreadNotifications()->count(),
        ]);
    }

    public function compteur(Request $request): JsonResponse
    {
        return response()->json([
            'non_lues' => $this->user($request)->unreadNotifications()->count(),
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preference($this->user($request))]);
    }

    public function enregistrerPreferences(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'recapitulatif' => ['required', Rule::in(RecapitulatifService::PREFERENCES)],
        ]);
        $avant = $user->notifications_courriel;
        $user->forceFill(['notifications_courriel' => $data['recapitulatif']])->save();
        app(AuditService::class)->enregistrer($user, 'notifications.preference', 'utilisateur', (string) $user->id, ['recapitulatif' => $avant], ['recapitulatif' => $data['recapitulatif']], null, 'succes');

        return response()->json(['data' => $this->preference($user->fresh())]);
    }

    /**
     * @return array{recapitulatif: string, courriel: ?string, actif: bool}
     */
    private function preference(User $user): array
    {
        return [
            'recapitulatif' => (string) ($user->notifications_courriel ?: 'quotidien'),
            'courriel' => $user->email,
            'actif' => (bool) config('gesbudep.notifications.recapitulatif', true),
        ];
    }

    public function show(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'data' => $this->cibles->carte($user, $this->trouver($user, $notification)),
        ]);
    }

    public function ouvrir(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);
        $notice = $this->trouver($user, $notification);
        $carte = $this->cibles->carte($user, $notice);

        if ($carte['ouverture'] === 'refusee') {
            $statut = ($carte['cible']['motif'] ?? '') === 'Le dossier lié à cette notification n’existe plus.' ? 422 : 403;

            return response()->json([
                'message' => $carte['cible']['motif'] ?? 'Cette notification ne peut pas être ouverte.',
                'liste' => $carte['cible']['liste'] ?? null,
            ], $statut);
        }

        if ($notice->read_at === null) {
            $notice->markAsRead();
        }

        return response()->json([
            'chemin' => $carte['cible']['chemin'] ?? null,
            'lue' => true,
            'non_lues' => $user->unreadNotifications()->count(),
        ]);
    }

    public function lire(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);
        $notice = $this->trouver($user, $notification);
        if ($notice->read_at === null) {
            $notice->markAsRead();
        }

        return response()->json([
            'data' => $this->cibles->carte($user, $notice->refresh()),
            'non_lues' => $user->unreadNotifications()->count(),
        ]);
    }

    public function nonLue(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);
        $notice = $this->trouver($user, $notification);
        if ($notice->read_at !== null) {
            $notice->markAsUnread();
        }

        return response()->json([
            'data' => $this->cibles->carte($user, $notice->refresh()),
            'non_lues' => $user->unreadNotifications()->count(),
        ]);
    }

    public function toutLire(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $user->unreadNotifications->each->markAsRead();

        return response()->json([
            'non_lues' => $user->unreadNotifications()->count(),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function trouver(User $user, string $id): DatabaseNotification
    {
        abort_unless(preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1, 404);

        return $user->notifications()->whereKey($id)->firstOrFail();
    }

    private function validerFiltres(Request $request): void
    {
        $request->validate([
            'lu' => ['nullable', 'in:lues,non_lues'],
            'module' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', 'string', 'max:40'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
        ]);
        $type = $request->string('type')->toString();
        if ($type !== '') {
            abort_unless(NotificationCatalog::connait($type), 422, 'Type de notification inconnu.');
        }
        $module = $request->string('module')->toString();
        if ($module !== '') {
            abort_unless(NotificationCatalog::typesDuModule($module) !== [], 422, 'Module inconnu.');
        }
    }

    /**
     * @param  Builder<DatabaseNotification>  $query
     * @return Builder<DatabaseNotification>
     */
    private function filtrer(Builder $query, Request $request): Builder
    {
        $lu = $request->string('lu')->toString();
        if ($lu === 'non_lues') {
            $query->whereNull('read_at');
        } elseif ($lu === 'lues') {
            $query->whereNotNull('read_at');
        }
        if ($request->filled('du')) {
            $query->whereDate('created_at', '>=', $request->date('du'));
        }
        if ($request->filled('au')) {
            $query->whereDate('created_at', '<=', $request->date('au'));
        }
        $type = $request->string('type')->toString();
        if ($type !== '') {
            $this->restreindreAuType($query, $type);
        }
        $module = $request->string('module')->toString();
        if ($module !== '') {
            $types = NotificationCatalog::typesDuModule($module);
            $query->where(function (Builder $groupe) use ($types): void {
                foreach ($types as $candidat) {
                    $groupe->orWhere(function (Builder $un) use ($candidat): void {
                        $this->restreindreAuType($un, $candidat);
                    });
                }
            });
        }

        return $query;
    }

    /**
     * @param  Builder<DatabaseNotification>  $query
     */
    private function restreindreAuType(Builder $query, string $type): void
    {
        $indices = NotificationCatalog::TYPES[$type]['indices'];
        $query->where(function (Builder $inner) use ($indices): void {
            foreach ($indices as $fragment) {
                $inner->orWhere('data', 'like', '%'.$fragment.'%');
            }
        });
    }
}
