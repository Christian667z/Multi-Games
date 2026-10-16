<?php
declare(strict_types=1);

function api_social(Database $db, ?array $user, array $input): never
{
    if (!$user) {
        json_response(['success' => false, 'error' => 'Connectez-vous pour utiliser les fonctions sociales.'], 401);
    }
    if (!verify_csrf()) {
        json_response(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
    }

    $action = (string)($input['action'] ?? '');
    $socialSuspended = !empty($user['social_suspended']);
    if ($action !== 'report_profile' && $socialSuspended) {
        json_response(['success' => false, 'error' => 'L’accès aux fonctions sociales de ce compte est suspendu.'], 403);
    }
    if ($action !== 'report_profile' && $db->getSetting('social_friends_enabled', '1') !== '1') {
        json_response(['success' => false, 'error' => 'Le système d’amis est temporairement désactivé.'], 403);
    }
    $targetId = filter_var($input['target_id'] ?? null, FILTER_VALIDATE_INT);
    switch ($action) {
        case 'friend_search':
            $query = (string)($input['query'] ?? '');
            $results = $db->searchFriendCandidates((int)$user['id'], $query);
            foreach ($results as &$result) {
                $result['relationship'] = $db->getFriendshipState((int)$user['id'], (int)$result['id']);
            }
            unset($result);
            if (!empty($user['social_suspended'])) {
                json_response(['success' => true, 'results' => []]);
            }
            json_response(['success' => true, 'results' => $results]);

        case 'friend_request':
            if (!$targetId) {
                json_response(['success' => false, 'error' => 'Joueur cible invalide.'], 400);
            }
            try {
                $relationship = $db->requestFriendship((int)$user['id'], $targetId);
            } catch (InvalidArgumentException $error) {
                json_response(['success' => false, 'error' => $error->getMessage()], 400);
            } catch (RuntimeException $error) {
                json_response(['success' => false, 'error' => $error->getMessage()], 409);
            }
            $db->logEvent('social', $user['display_name'] . ' a envoyé une demande d’ami', ['target_id' => $targetId], (int)$user['id'], $user['display_name'], 'info');
            json_response(['success' => true, 'relationship' => $relationship]);

        case 'friend_accept':
        case 'friend_decline':
            if (!$targetId) {
                json_response(['success' => false, 'error' => 'Demande en attente introuvable.'], 404);
            }
            try {
                $responded = $db->respondToFriendRequest((int)$user['id'], $targetId, $action === 'friend_accept');
            } catch (RuntimeException $error) {
                json_response(['success' => false, 'error' => $error->getMessage()], 409);
            }
            if (!$responded) {
                json_response(['success' => false, 'error' => 'Demande en attente introuvable.'], 404);
            }
            $db->logEvent('social', $user['display_name'] . ($action === 'friend_accept' ? ' a accepté une demande d’ami' : ' a refusé une demande d’ami'), ['target_id' => $targetId], (int)$user['id'], $user['display_name'], 'info');
            json_response(['success' => true]);

        case 'friend_remove':
            if (!$targetId || !$db->removeFriendship((int)$user['id'], $targetId)) {
                json_response(['success' => false, 'error' => 'Relation introuvable.'], 404);
            }
            $db->logEvent('social', $user['display_name'] . ' a supprimé une amitié', ['target_id' => $targetId], (int)$user['id'], $user['display_name'], 'info');
            json_response(['success' => true]);

        case 'friend_block':
            if (!$targetId) {
                json_response(['success' => false, 'error' => 'Joueur cible invalide.'], 400);
            }
            try {
                $db->blockUser((int)$user['id'], $targetId);
            } catch (InvalidArgumentException $error) {
                json_response(['success' => false, 'error' => $error->getMessage()], 400);
            } catch (RuntimeException $error) {
                json_response(['success' => false, 'error' => $error->getMessage()], 409);
            }
            $db->logEvent('social', $user['display_name'] . ' a bloqué un joueur', ['target_id' => $targetId], (int)$user['id'], $user['display_name'], 'warning');
            json_response(['success' => true]);

        case 'friends_list':
            json_response(['success' => true, 'friends' => $db->listFriends((int)$user['id'])]);

        case 'friend_requests':
            json_response([
                'success' => true,
                'requests' => $db->listFriendRequests((int)$user['id']),
                'outgoing' => $db->listOutgoingFriendRequests((int)$user['id']),
            ]);

        case 'report_profile':
            if (!$targetId) {
                json_response(['success' => false, 'error' => 'Profil cible invalide.'], 400);
            }
            try {
                $reportId = $db->createProfileReport((int)$user['id'], $targetId, (string)($input['reason'] ?? ''));
            } catch (InvalidArgumentException $error) {
                json_response(['success' => false, 'error' => $error->getMessage()], 400);
            } catch (RuntimeException $error) {
                json_response(['success' => false, 'error' => $error->getMessage()], 409);
            }
            $db->logEvent('social', $user['display_name'] . ' a signalé un profil', ['report_id' => $reportId, 'target_id' => $targetId], (int)$user['id'], $user['display_name'], 'warning');
            json_response(['success' => true, 'report_id' => $reportId]);

        default:
            json_response(['success' => false, 'error' => 'Action sociale inconnue.'], 400);
    }
}
