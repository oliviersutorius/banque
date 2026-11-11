<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\HttpException;
use Banque\RequestGuard;

final class RequestGuardTest extends TestCase
{
    public function testAccepteLesHotesLocauxQuelQueSoitLePort(): void
    {
        $guard = new RequestGuard();
        foreach (['localhost:8000', 'LOCALHOST:5173', '127.0.0.1:8001', '[::1]:8000', 'localhost'] as $host) {
            $guard->check(['HTTP_HOST' => $host, 'REQUEST_METHOD' => 'GET']);
        }
        $this->assertTrue(true);
    }

    public function testRefuseUnAutreHoteDnsRebinding(): void
    {
        $guard = new RequestGuard();
        foreach (['rebinding.example:8000', 'localhost.example:8000', '127.0.0.1.nip.io:8000', '[::2]:8000', ''] as $host) {
            $this->assertStatus(403, static fn () => $guard->check(['HTTP_HOST' => $host, 'REQUEST_METHOD' => 'GET']));
        }
        $this->assertStatus(403, static fn () => $guard->check(['REQUEST_METHOD' => 'GET']));
    }

    public function testAccepteLesHotesSupplementaires(): void
    {
        $guard = RequestGuard::fromList(' comptes.example.fr , ');
        $guard->check(['HTTP_HOST' => 'Comptes.example.fr', 'REQUEST_METHOD' => 'GET']);
        $this->assertStatus(403, static fn () => $guard->check(['HTTP_HOST' => 'autre.example.fr', 'REQUEST_METHOD' => 'GET']));
    }

    public function testAccepteUneEcritureDeLaMemeOrigine(): void
    {
        $guard = new RequestGuard();
        // Serveur PHP direct, front Vite (proxy), sans en-tête Origin (curl), IPv6
        $guard->check(['HTTP_HOST' => 'localhost:8000', 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost:8000', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $guard->check(['HTTP_HOST' => 'localhost:5173', 'REQUEST_METHOD' => 'PATCH', 'HTTP_ORIGIN' => 'http://localhost:5173']);
        $guard->check(['HTTP_HOST' => 'localhost:8000', 'REQUEST_METHOD' => 'DELETE']);
        $guard->check(['HTTP_HOST' => '[::1]:8000', 'REQUEST_METHOD' => 'PUT', 'HTTP_ORIGIN' => 'http://[::1]:8000']);
        $this->assertTrue(true);
    }

    public function testRefuseUneEcritureVenueDUnAutreSite(): void
    {
        $guard = new RequestGuard();
        $cases = [
            ['HTTP_ORIGIN' => 'https://site-malveillant.example'],
            ['HTTP_ORIGIN' => 'http://localhost:3000'],
            ['HTTP_ORIGIN' => 'null'],
            ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
            ['HTTP_SEC_FETCH_SITE' => 'same-site'],
        ];
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach ($cases as $headers) {
                $this->assertStatus(403, static fn () => $guard->check(['HTTP_HOST' => 'localhost:8000', 'REQUEST_METHOD' => $method] + $headers));
            }
        }
    }

    public function testLaLectureNeVerifiePasLOrigine(): void
    {
        // Une lecture venue d'un autre site reste illisible pour lui : l'API n'envoie aucun en-tête CORS
        (new RequestGuard())->check(['HTTP_HOST' => 'localhost:8000', 'REQUEST_METHOD' => 'GET', 'HTTP_ORIGIN' => 'https://site.example']);
        $this->assertTrue(true);
    }

    public function testExigeUnCorpsAnnonceEnJson(): void
    {
        RequestGuard::requireJson(['CONTENT_TYPE' => 'application/json']);
        RequestGuard::requireJson(['CONTENT_TYPE' => 'Application/JSON; charset=utf-8']);
        foreach (['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', 'application/json-patch', ''] as $type) {
            $this->assertStatus(415, static fn () => RequestGuard::requireJson(['CONTENT_TYPE' => $type]));
        }
        $this->assertStatus(415, static fn () => RequestGuard::requireJson([]));
    }

    private function assertStatus(int $status, callable $fn): void
    {
        try {
            $fn();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status());
            return;
        }
        $this->assertTrue(false, 'HttpException ' . $status . ' attendue, aucune levée');
    }
}
