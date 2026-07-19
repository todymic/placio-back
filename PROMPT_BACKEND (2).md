# Seating App — Backend Spring Boot — Prompt de génération complet

## Contexte du projet

Application de gestion de réservation de sièges dans des événements (type seats.io).
Ce document est à fournir à ton IA d'IDE (Cursor, Copilot, etc.) pour générer le backend complet.

---

## Stack technique

- **Java 25**
- **Spring Boot 3.2.5**
- **Spring Security** (filtre API Key custom)
- **Spring Data JPA** + **PostgreSQL** (JSONB pour les objets du plan)
- **Spring Data Redis** (holds temporaires avec TTL)
- **Lombok**
- **Hibernate Types** (`hypersistence-utils-hibernate-63`) pour le type JSONB natif
- **Maven**

---

## Package racine : `com.placio`

---

## Modèle de données

### Entité `ApiKey`

Une paire **key + secret** : la `keyId` est publique (visible, sert à identifier la paire), le `secretHash` est le hash BCrypt du secret brut qui n'est montré qu'une seule fois à la création.

```java
@Entity @Table(name = "api_keys")
- UUID id (PK, auto)
- String keyId (unique, not null)   — préfixe lisible, ex: "pk_bo_a3f9c2" (toujours visible)
- String secretHash (not null)      — BCrypt hash du secret brut (jamais retourné en clair)
- String name — label lisible
- ApiKeyScope scope — enum : BACKOFFICE, PUBLIC
- boolean active (default true)
- LocalDateTime createdAt
- LocalDateTime lastUsedAt          — mis à jour à chaque appel authentifié
```

**Format des identifiants générés :**
- `keyId`  : `"pk_" + scope_prefix + "_" + 8 chars aléatoires` — ex: `pk_bo_a3f9c2d1`
- `secret` : `"sk_" + scope_prefix + "_" + 32 chars aléatoires` — ex: `sk_bo_a1b2c3d4e5f6...`
- scope_prefix : `bo` pour BACKOFFICE, `pub` pour PUBLIC

**Authentification HTTP :**
Le client envoie les deux valeurs dans les headers :
```
X-Api-Key-Id: pk_bo_a3f9c2d1
X-Api-Key-Secret: sk_bo_a1b2c3...
```
Le filtre récupère le `keyId`, charge l'`ApiKey` depuis la DB, puis vérifie le secret avec `BCryptPasswordEncoder.matches(secret, secretHash)`.

### Enum `ApiKeyScope`

```java
public enum ApiKeyScope { BACKOFFICE, PUBLIC }
```

### Entité `Category`

```java
@Entity @Table(name = "categories")
- UUID id (PK, auto)
- String name (not null)
- String key (unique, not null) — ex: "cat-vip"
- String color (not null) — ex: "#FF5733"
- LocalDateTime createdAt
```

### Entité `Chart`

```java
@Entity @Table(name = "charts")
- UUID id (PK, auto)
- String name (not null)
- String slug (unique, not null)
- String objectsJson — colonne PostgreSQL de type JSONB, annotée @Type(JsonType.class)
  stocke la liste de ChartObjectNode (POJO sérialisé en JSON)
- LocalDateTime createdAt
- LocalDateTime updatedAt
```

#### POJO `ChartObjectNode` (non-entité, sérialisé dans le JSON)

```java
public class ChartObjectNode {
    String type;        // "section" | "row" | "seat" | "table" | "shape"
    String key;         // identifiant unique dans le chart (ex: "A1", "T-1")
    String label;
    String categoryKey; // référence à Category.key
    double x;
    double y;
    double rotation;    // en degrés
    // Pour "table" :
    Integer seatCount;  // nombre de sièges autour de la table (défaut 6)
    // Pour "shape" (décoration non réservable) :
    String shapeType;   // "rect" | "circle" | "diamond" | "square"
    boolean selectable; // false pour les formes décoratives
    List<ChartObjectNode> children; // pour section → rows → seats
}
```

### Entité `Event`

```java
@Entity @Table(name = "events")
- UUID id (PK, auto)
- String title (not null)
- String identifier (unique, not null) — slug utilisateur ex: "concert-2024-01"
- @ManyToOne Chart chart — nullable (on peut créer un event sans chart)
- LocalDateTime createdAt
- LocalDateTime updatedAt
```

### Entité `EventSeat`

```java
@Entity @Table(name = "event_seats")
- UUID id (PK, auto)
- @ManyToOne Event event (not null)
- String seatKey (not null) — clé du siège dans le chart (ChartObjectNode.key)
- SeatStatus status (not null, default AVAILABLE)
- String holdToken — UUID du client qui tient le siège (null si non en hold)
- LocalDateTime heldUntil — expiration du hold
Contrainte unique : (event_id, seat_key)
```

### Enum `SeatStatus`

```java
public enum SeatStatus { AVAILABLE, HOLD, BOOKED, CANCELED }
```

---

## Repositories (Spring Data JPA)

- `ApiKeyRepository` : findByKeyIdAndActiveTrue(String keyId)
- `CategoryRepository` : findByKey(String key)
- `ChartRepository` : findBySlug(String slug)
- `EventRepository` : findByIdentifier(String identifier)
- `EventSeatRepository` :
  - findByEventIdAndSeatKeyIn(UUID eventId, List<String> seatKeys)
  - findByEventId(UUID eventId)
  - findByStatusAndHeldUntilBefore(SeatStatus status, LocalDateTime now) — pour le job d'expiration
  - findByEventIdAndStatusAndHeldUntilBefore(UUID eventId, SeatStatus status, LocalDateTime now) — vérif inline
  - @Modifying @Query("UPDATE EventSeat e SET e.status = AVAILABLE, e.holdToken = null, e.heldUntil = null WHERE e.id IN :ids") — batch release

---

## DTOs

### `ApiKeyRequest` { String name; ApiKeyScope scope; }
### `ApiKeyCreatedResponse` { UUID id; String name; String keyId; String secret; ApiKeyScope scope; LocalDateTime createdAt; }
  — **retourné UNE SEULE FOIS à la création**, contient le secret en clair. Le stocker immédiatement côté client.
### `ApiKeyResponse` { UUID id; String name; String keyId; ApiKeyScope scope; boolean active; LocalDateTime createdAt; LocalDateTime lastUsedAt; }
  — retourné pour les lectures (jamais de secret)

### `CategoryRequest` { @NotBlank String name; @NotBlank String key; @NotBlank String color; }
### `CategoryResponse` { UUID id; String name; String key; String color; }

### `ChartRequest` { @NotBlank String name; @NotBlank String slug; }
### `ChartResponse` { UUID id; String name; String slug; List<ChartObjectNode> objects; LocalDateTime updatedAt; }
### `ChartObjectsUpdateRequest` { List<ChartObjectNode> objects; }

### `EventRequest` { @NotBlank String title; @NotBlank String identifier; UUID chartId; }
### `EventResponse` { UUID id; String title; String identifier; UUID chartId; String chartName; LocalDateTime createdAt; }
### `EventDetailResponse` extends EventResponse { List<EventSeatStatusDto> seats; }
  - `EventSeatStatusDto` { String seatKey; SeatStatus status; }

### `HoldRequest` { @NotEmpty List<String> seatKeys; @NotBlank String holdToken; }
### `BookRequest` { @NotEmpty List<String> seatKeys; @NotBlank String holdToken; }
### `ReleaseRequest` { @NotEmpty List<String> seatKeys; @NotBlank String holdToken; }
### `ChangeStatusRequest` { @NotEmpty List<String> seatKeys; @NotNull SeatStatus status; } — BO only

---

## Services

### `ApiKeyService`
- `ApiKeyAuthentication validate(String keyId, String rawSecret)` :
  1. Charge l'ApiKey par `keyId` (active = true), lève `UnauthorizedException` si introuvable
  2. Vérifie `BCryptPasswordEncoder.matches(rawSecret, apiKey.secretHash)`, lève 401 si KO
  3. Met à jour `lastUsedAt = now()` en DB
  4. Retourne un objet portant le scope
- `ApiKeyCreatedResponse create(ApiKeyRequest req)` :
  1. Génère `keyId = "pk_" + scopePrefix + "_" + randomAlphanumeric(8)`
  2. Génère `rawSecret = "sk_" + scopePrefix + "_" + randomAlphanumeric(32)`
  3. Hash le secret : `secretHash = BCryptPasswordEncoder.encode(rawSecret)`
  4. Sauvegarde en DB (avec secretHash, jamais rawSecret)
  5. Retourne `ApiKeyCreatedResponse` avec le `rawSecret` en clair — **unique occasion**
- `List<ApiKeyResponse> findAll()` — sans secret
- `void deactivate(UUID id)`

### `CategoryService`
- CRUD standard + `List<CategoryResponse> findAll()`

### `ChartService`
- `ChartResponse create(ChartRequest req)`
- `ChartResponse findById(UUID id)` et `findBySlug(String slug)`
- `List<ChartResponse> findAll()`
- `ChartResponse update(UUID id, ChartRequest req)`
- `void delete(UUID id)`
- `ChartResponse updateObjects(UUID id, List<ChartObjectNode> objects)` — sérialise en JSON et sauvegarde

### `EventService`
- `EventResponse create(EventRequest req)` — si `chartId` fourni, lie le chart, initialise les EventSeat à AVAILABLE pour chaque siège (type="seat") du chart
- `EventDetailResponse findById(UUID id)` — inclut tous les EventSeat
- `List<EventResponse> findAll()`
- `EventResponse update(UUID id, EventRequest req)`
- `void delete(UUID id)` — cascade sur EventSeat
- `EventResponse linkChart(UUID eventId, UUID chartId)` — relie un chart, (re)génère les EventSeat

### `BookingService`
- Dépend de `StringRedisTemplate`
- Hold TTL : configurable via `seating.hold-duration-minutes` (défaut 10 min)
- Clé Redis hold    : `"hold:{eventId}:{seatKey}"` → valeur = holdToken
- Clé Redis session : `"session_seats:{holdToken}"` → valeur = JSON list des seatKeys tenus par ce token
  (permet de retrouver tous les sièges d'un holdToken sans scanner la DB)

#### Gestion des doublons dans une même requête

Avant tout traitement, dédupliquer la liste `seatKeys` reçue :
```
seatKeys = seatKeys.stream().distinct().toList()
```
Si la liste déduplicée est vide → lever `IllegalArgumentException("La liste de sièges est vide")`.
Si des doublons ont été détectés → logger un WARNING (ne pas rejeter la requête, juste dédupliquer).

#### Gestion du re-hold par le même token

Si un siège est déjà en HOLD avec le **même** holdToken (le client re-soumet accidentellement) :
- Ne pas lever d'exception
- Rafraîchir le TTL Redis à `now + holdDuration` (renouvellement silencieux)
- Retourner le siège comme si le hold avait réussi

Si un siège est en HOLD avec un **autre** holdToken → `SeatNotAvailableException`.

#### Atomicité — pipeline Redis

Le hold doit être **atomique** : soit tous les sièges sont posés, soit aucun.
Utiliser un pipeline Redis en deux passes :

```
Passe 1 — vérification (lecture seule) :
  Pour chaque seatKey dédupliqué :
    Lire la valeur Redis "hold:{eventId}:{seatKey}"
    Si valeur présente ET != holdToken → collecter dans conflictKeys[]
  Si conflictKeys non vide → lever SeatNotAvailableException avec la liste des sièges en conflit

Passe 2 — écriture (si passe 1 OK) :
  Exécuter en pipeline Redis :
    Pour chaque seatKey :
      SETEX "hold:{eventId}:{seatKey}" holdDurationSeconds holdToken
    SETEX "session_seats:{holdToken}" holdDurationSeconds JSON(seatKeys)
  Mettre à jour en DB tous les EventSeat en HOLD + holdToken + heldUntil = now+holdDuration
    → Utiliser une @Transactional + saveAll() en une seule requête batch
```

```
holdSeats(UUID eventId, List<String> seatKeys, String holdToken):
  1. Dédupliquer seatKeys
  2. Vérifier que tous les EventSeat existent pour cet event (404 si manquant)
  3. Vérifier en DB que chaque siège est AVAILABLE ou HOLD+même holdToken
     → Collecter les sièges bloquants (HOLD autre token, BOOKED, CANCELED)
     → Si liste non vide : lever SeatNotAvailableException({ conflictingSeats: [...] })
  4. Passe 1 Redis : vérifier les verrous (voir ci-dessus)
  5. Passe 2 Redis : poser les verrous en pipeline
  6. @Transactional : mettre à jour les EventSeat en DB
  7. Retourner HoldResponse { holdToken, seatKeys, expiresAt }

bookSeats(UUID eventId, List<String> seatKeys, String holdToken):
  1. Dédupliquer seatKeys
  2. Charger les EventSeat → vérifier que chacun est en HOLD avec CE holdToken
     → Si un siège n'est pas en HOLD ou a un autre token : lever SeatNotAvailableException
  3. @Transactional : passer tous en BOOKED, effacer holdToken + heldUntil
  4. Supprimer les clés Redis "hold:{eventId}:{seatKey}" pour chaque siège
  5. Supprimer la clé Redis "session_seats:{holdToken}"
  6. Retourner BookResponse { bookedSeats, eventId }

releaseSeats(UUID eventId, List<String> seatKeys, String holdToken):
  1. Dédupliquer seatKeys
  2. Charger les EventSeat → vérifier HOLD + même holdToken
  3. @Transactional : passer en AVAILABLE, effacer holdToken + heldUntil
  4. Supprimer les clés Redis correspondantes
  5. Si "session_seats:{holdToken}" n'a plus de sièges → supprimer aussi cette clé

changeStatus(UUID eventId, List<String> seatKeys, SeatStatus newStatus):
  1. Dédupliquer seatKeys
  2. Passer directement les EventSeat au nouveau statut (usage BO, pas de vérification holdToken)
  3. Si newStatus != HOLD : nettoyer les clés Redis hold + session_seats pour chaque siège libéré
```

#### DTOs supplémentaires pour BookingService

```java
// Réponse du hold
HoldResponse { String holdToken; List<String> seatKeys; LocalDateTime expiresAt; int durationSeconds; }

// Réponse du book
BookResponse { List<String> bookedSeats; UUID eventId; LocalDateTime bookedAt; }

// Réponse d'erreur de conflit (409)
SeatConflictResponse { String message; List<SeatConflictDetail> conflicts; }
SeatConflictDetail { String seatKey; SeatStatus currentStatus; } // status actuel du siège bloquant
```

---

### `HoldExpirationJob` — release automatique des sièges expirés

- `@Scheduled(fixedDelay = 30000)` — toutes les **30 secondes** (plus réactif que 60s)
- Utilise `@Transactional`

```
expireHolds():
  1. Charger depuis DB tous les EventSeat où status = HOLD ET heldUntil < now()
  2. Si liste vide → return (no-op)
  3. Pour chaque siège expiré :
       a. Collecter la clé Redis "hold:{eventId}:{seatKey}" à supprimer
       b. Collecter le holdToken pour nettoyer "session_seats:{holdToken}"
  4. Passer tous les EventSeat en AVAILABLE, effacer holdToken + heldUntil → saveAll() batch
  5. Supprimer toutes les clés Redis collectées en pipeline (delete en masse)
  6. Logger : "Expiration automatique : {n} sièges libérés pour {m} tokens"
```

**Sécurité double-expiration :**
Redis TTL et heldUntil DB sont deux gardiens indépendants. Si Redis redémarre (perte des clés),
le job DB reste le filet de sécurité. Si la DB est lente, Redis refuse de nouveaux holds sur ces sièges.

**Cas edge — siège expiré Redis mais pas encore traité par le job :**
Dans `holdSeats` passe 1, si la clé Redis n'existe plus MAIS le statut DB est encore HOLD :
- Vérifier `heldUntil < now()` → si oui, considérer le siège comme AVAILABLE et laisser le nouveau hold passer
- Le job nettoiera la DB lors de son prochain passage (ou le faire inline ici)

### `HoldExpirationJob`
Voir section détaillée dans `BookingService` ci-dessus.
Résumé : `@Scheduled(fixedDelay = 30000)`, cherche les EventSeat HOLD expirés, les repasse en AVAILABLE en batch, nettoie Redis en pipeline.

---

## Sécurité — Filtre `ApiKeyAuthFilter`

Étend `OncePerRequestFilter`.

Logique :
1. Lire les headers `X-Api-Key-Id` et `X-Api-Key-Secret`
2. Si l'un des deux est absent → `response.sendError(401, "Missing API credentials")`
3. Appeler `ApiKeyService.validate(keyId, rawSecret)` → si invalide/inactive → 401
4. Créer un `UsernamePasswordAuthenticationToken` avec comme principal le `keyId`, et comme granted authority `"ROLE_" + scope.name()`
5. Mettre dans `SecurityContextHolder`

Routes publiques (pas de filtre) : `GET /actuator/health`

### `SecurityConfig`

- Désactiver CSRF (API REST stateless)
- SessionManagement : STATELESS
- Ajouter `ApiKeyAuthFilter` avant `UsernamePasswordAuthenticationFilter`
- Autorisation par scope :
  - `POST/PUT/DELETE /api/charts/**` → `ROLE_BACKOFFICE`
  - `POST/PUT/DELETE /api/events/**` (sauf `/hold`, `/book`, `/release`) → `ROLE_BACKOFFICE`
  - `POST /api/events/*/change-status` → `ROLE_BACKOFFICE`
  - `POST/PUT/DELETE /api/categories/**` → `ROLE_BACKOFFICE`
  - `GET/POST /api/api-keys/**` → `ROLE_BACKOFFICE`
  - `POST /api/events/*/hold` → `ROLE_PUBLIC` ou `ROLE_BACKOFFICE`
  - `POST /api/events/*/book` → `ROLE_PUBLIC` ou `ROLE_BACKOFFICE`
  - `POST /api/events/*/release` → `ROLE_PUBLIC` ou `ROLE_BACKOFFICE`
  - Tout le reste en GET → `authenticated()`

---

## Contrôleurs REST

### `ApiKeyController` — `/api/api-keys` (BACKOFFICE)
- `GET /` → liste toutes les clés
- `POST /` → créer une clé
- `DELETE /{id}` → désactiver

### `CategoryController` — `/api/categories`
- CRUD complet
- `GET /` → public (authenticated)
- `POST, PUT, DELETE` → BACKOFFICE

### `ChartController` — `/api/charts`
- `GET /` → liste
- `GET /{id}` → détail avec objets
- `POST /` → créer (BACKOFFICE)
- `PUT /{id}` → modifier métadonnées (BACKOFFICE)
- `PUT /{id}/objects` → sauvegarder les objets du plan (BACKOFFICE)
- `DELETE /{id}` → supprimer (BACKOFFICE)

### `EventController` — `/api/events`
- `GET /` → liste
- `GET /{id}` → détail avec statuts des sièges
- `POST /` → créer (BACKOFFICE)
- `PUT /{id}` → modifier (BACKOFFICE)
- `DELETE /{id}` → supprimer (BACKOFFICE)
- `POST /{id}/link-chart/{chartId}` → lier un chart (BACKOFFICE)

### `BookingController` — `/api/events/{eventId}`
- `POST /hold` → `HoldRequest` → PUBLIC + BACKOFFICE
- `POST /book` → `BookRequest` → PUBLIC + BACKOFFICE
- `POST /release` → `ReleaseRequest` → PUBLIC + BACKOFFICE
- `POST /change-status` → `ChangeStatusRequest` → BACKOFFICE only
- `GET /seats` → liste des statuts → authenticated

---

## Gestion des exceptions

### Exceptions custom
- `ResourceNotFoundException` extends `RuntimeException` — 404
- `SeatNotAvailableException` extends `RuntimeException` — 409
- `UnauthorizedException` extends `RuntimeException` — 401
- `DuplicateKeyException` extends `RuntimeException` — 409

### `GlobalExceptionHandler` (`@RestControllerAdvice`)
- Intercepter les 4 exceptions custom → répondre avec `{ "error": message, "status": code }`
- Intercepter `MethodArgumentNotValidException` → 400 avec les détails des champs invalides

---

## Configuration

### `application.yml`

```yaml
spring:
  application:
    name: placio-backend

  datasource:
    url: jdbc:postgresql://localhost:5432/seating
    username: postgres
    password: postgres
    driver-class-name: org.postgresql.Driver

  jpa:
    hibernate:
      ddl-auto: update
    show-sql: true
    properties:
      hibernate:
        dialect: org.hibernate.dialect.PostgreSQLDialect
        format_sql: true

  data:
    redis:
      host: localhost
      port: 6379
      timeout: 2000ms

server:
  port: 8080

seating:
  hold-duration-minutes: 10          # durée du hold en minutes
  hold-expiration-check-ms: 30000    # fréquence du job d'expiration en ms
  api-key-header: X-API-Key
  session-duration-minutes: 60       # durée de vie du session token JWT

management:
  endpoints:
    web:
      exposure:
        include: health
```

### `RedisConfig`

Déclarer un bean `StringRedisTemplate` avec `StringRedisSerializer` pour clés et valeurs.

### `JacksonConfig`

Configurer `ObjectMapper` avec :
- `JavaTimeModule` (sérialisation des dates Java 8)
- `WRITE_DATES_AS_TIMESTAMPS = false`
- `FAIL_ON_UNKNOWN_PROPERTIES = false`

---

## `pom.xml` — dépendances clés

```xml
<parent>
  <groupId>org.springframework.boot</groupId>
  <artifactId>spring-boot-starter-parent</artifactId>
  <version>3.2.5</version>
</parent>

<!-- Java 21 -->
<properties><java.version>25</java.version></properties>

<dependencies>
  spring-boot-starter-web
  spring-boot-starter-data-jpa
  spring-boot-starter-data-redis
  spring-boot-starter-security
  spring-boot-starter-validation
  org.postgresql:postgresql (runtime)
  org.projectlombok:lombok (optional)
  com.fasterxml.jackson.datatype:jackson-datatype-jsr310
  io.hypersistence:hypersistence-utils-hibernate-63:3.7.3
  spring-boot-starter-test (test)
  spring-security-test (test)
</dependencies>
```

---

## Structure des packages à générer

```
src/main/java/com/seating/
├── SeatingApplication.java                   @SpringBootApplication + @EnableScheduling
├── config/
│   ├── SecurityConfig.java
│   ├── RedisConfig.java
│   └── JacksonConfig.java
├── controller/
│   ├── ApiKeyController.java
│   ├── CategoryController.java
│   ├── ChartController.java
│   ├── EventController.java
│   └── BookingController.java
├── model/
│   ├── ApiKey.java
│   ├── ApiKeyScope.java
│   ├── Category.java
│   ├── Chart.java
│   ├── ChartObjectNode.java
│   ├── Event.java
│   ├── EventSeat.java
│   └── SeatStatus.java
├── repository/
│   ├── ApiKeyRepository.java
│   ├── CategoryRepository.java
│   ├── ChartRepository.java
│   ├── EventRepository.java
│   └── EventSeatRepository.java
├── service/
│   ├── ApiKeyService.java
│   ├── CategoryService.java
│   ├── ChartService.java
│   ├── EventService.java
│   ├── BookingService.java
│   └── HoldExpirationJob.java
├── dto/
│   ├── ApiKeyRequest.java / ApiKeyResponse.java / ApiKeyCreatedResponse.java
│   ├── CategoryRequest.java / CategoryResponse.java
│   ├── ChartRequest.java / ChartResponse.java / ChartObjectsUpdateRequest.java
│   ├── EventRequest.java / EventResponse.java / EventDetailResponse.java / EventSeatStatusDto.java
│   ├── HoldRequest.java / BookRequest.java / ReleaseRequest.java / ChangeStatusRequest.java
│   └── ErrorResponse.java
├── filter/
│   └── ApiKeyAuthFilter.java
├── security/
│   └── ApiKeyAuthentication.java
└── exception/
    ├── ResourceNotFoundException.java
    ├── SeatNotAvailableException.java
    ├── UnauthorizedException.java
    ├── DuplicateKeyException.java
    └── GlobalExceptionHandler.java

src/main/resources/
└── application.yml
```

---

## Authentification utilisateur via Keycloak

### Concept

Deux mécanismes d'authentification coexistent dans le backend :

| Mécanisme | Usage | Qui l'utilise |
|---|---|---|
| **API Key** (X-Api-Key-Id + Secret) | Accès machine-to-machine | Serveur du client → notre API |
| **JWT Keycloak** (Bearer token OIDC) | Accès utilisateur connecté | Back-office Vue.js → notre API |

Les routes BO (`/api/charts`, `/api/events`, `/api/api-keys`…) acceptent les deux mécanismes.
Les routes publiques (`/api/public/**`) n'acceptent que les session tokens internes (inchangé).

---

### Dépendance à ajouter dans `pom.xml`

```xml
<!-- OAuth2 Resource Server (validation JWT Keycloak) -->
<dependency>
  <groupId>org.springframework.boot</groupId>
  <artifactId>spring-boot-starter-oauth2-resource-server</artifactId>
</dependency>
```

---

### Configuration `application.yml` — ajout OAuth2

```yaml
spring:
  security:
    oauth2:
      resourceserver:
        jwt:
          issuer-uri: http://keycloak:8180/realms/seating
          # En dev local sans Docker, utiliser :
          # issuer-uri: http://localhost:8180/realms/seating

keycloak:
  realm: seating
  client-id: placio-backend
  # Claim du JWT Keycloak qui contient les rôles realm
  roles-claim-path: realm_access.roles
```

---

### Mise à jour `SecurityConfig`

Le filtre doit accepter **deux types de credentials** sur les routes protégées :

```
Pour chaque requête entrante :

  Si header "Authorization: Bearer <token>" présent :
    → Valider le JWT via Spring OAuth2 Resource Server (automatique)
    → Extraire les rôles depuis le claim "realm_access.roles"
    → Mapper "ROLE_BACKOFFICE" et "ROLE_USER" comme GrantedAuthority

  Sinon si headers "X-Api-Key-Id" + "X-Api-Key-Secret" présents :
    → Passer par ApiKeyAuthFilter (comportement inchangé)

  Sinon si header "X-Session-Token" présent (routes /api/public/**) :
    → Passer par SessionTokenFilter (comportement inchangé)

  Sinon → 401
```

**Nouveau bean `JwtAuthenticationConverter`** — extrait les rôles Keycloak :
```java
@Bean
JwtAuthenticationConverter jwtAuthenticationConverter() {
  JwtGrantedAuthoritiesConverter converter = new JwtGrantedAuthoritiesConverter();
  converter.setAuthoritiesClaimName("realm_access.roles");  // claim Keycloak
  converter.setAuthorityPrefix("ROLE_");

  JwtAuthenticationConverter jwtConverter = new JwtAuthenticationConverter();
  jwtConverter.setJwtGrantedAuthoritiesConverter(jwt -> {
    // Extraire realm_access.roles (structure imbriquée Keycloak)
    Map<String, Object> realmAccess = jwt.getClaimAsMap("realm_access");
    if (realmAccess == null) return List.of();
    List<String> roles = (List<String>) realmAccess.get("roles");
    if (roles == null) return List.of();
    return roles.stream()
        .map(role -> new SimpleGrantedAuthority("ROLE_" + role))
        .collect(Collectors.toList());
  });
  return jwtConverter;
}
```

Ajouter dans `httpSecurity` :
```java
.oauth2ResourceServer(oauth2 -> oauth2
    .jwt(jwt -> jwt.jwtAuthenticationConverter(jwtAuthenticationConverter()))
)
```

---

### Entité `UserProfile` — cache local des infos utilisateur

On ne duplique pas les données d'authentification (gérées par Keycloak), mais on stocke
les préférences et métadonnées propres à notre application.

```java
@Entity @Table(name = "user_profiles")
- UUID id (PK, auto)
- String keycloakId (unique, not null)  — sub du JWT Keycloak (UUID Keycloak)
- String email (not null)               — synchronisé depuis le token
- String displayName                    — synchronisé depuis preferred_username
- LocalDateTime firstLoginAt
- LocalDateTime lastLoginAt
- boolean active (default true)
```

**Synchronisation automatique** : à chaque requête authentifiée par JWT Keycloak,
un `@Component UserSyncService` vérifie si le `keycloakId` existe en DB.
Si non → crée le profil. Si oui → met à jour `lastLoginAt` + `email` si changé.

---

### Service `UserSyncService`

```
syncUser(Jwt jwt) → UserProfile :
  String keycloakId  = jwt.getSubject()
  String email       = jwt.getClaimAsString("email")
  String displayName = jwt.getClaimAsString("preferred_username")

  UserProfile profile = userProfileRepository.findByKeycloakId(keycloakId)
      .orElseGet(() -> {
          UserProfile p = new UserProfile();
          p.setKeycloakId(keycloakId);
          p.setFirstLoginAt(now());
          return p;
      });

  profile.setEmail(email);
  profile.setDisplayName(displayName);
  profile.setLastLoginAt(now());
  return userProfileRepository.save(profile);
```

---

### Endpoint `/api/account` — profil de l'utilisateur connecté

#### `AccountController` — `/api/account`

Accessible uniquement avec un **JWT Keycloak valide** (pas avec API Key).

- `GET /api/account/me` → retourne le profil de l'utilisateur connecté
- `PUT /api/account/me` → met à jour les préférences (displayName)
- `GET /api/account/me/api-keys` → liste les API Keys créées par cet utilisateur (BACKOFFICE uniquement)

#### DTO `AccountResponse`

```java
AccountResponse {
    UUID id;
    String keycloakId;
    String email;
    String displayName;
    List<String> roles;          // rôles Keycloak : ["ROLE_BACKOFFICE", "ROLE_USER"]
    LocalDateTime firstLoginAt;
    LocalDateTime lastLoginAt;
}
```

#### DTO `AccountUpdateRequest`

```java
AccountUpdateRequest {
    @NotBlank String displayName;
}
```

#### Implémentation `AccountController`

```java
@RestController
@RequestMapping("/api/account")
public class AccountController {

    @GetMapping("/me")
    public ResponseEntity<AccountResponse> getMe(@AuthenticationPrincipal Jwt jwt) {
        UserProfile profile = userSyncService.syncUser(jwt);
        List<String> roles = jwt.getClaimAsMap("realm_access") ...;  // extraire les rôles
        return ResponseEntity.ok(AccountResponse.from(profile, roles));
    }

    @PutMapping("/me")
    public ResponseEntity<AccountResponse> updateMe(
            @AuthenticationPrincipal Jwt jwt,
            @Valid @RequestBody AccountUpdateRequest req) {
        UserProfile profile = userSyncService.syncUser(jwt);
        profile.setDisplayName(req.getDisplayName());
        userProfileRepository.save(profile);
        return ResponseEntity.ok(AccountResponse.from(profile, extractRoles(jwt)));
    }

    @GetMapping("/me/api-keys")
    @PreAuthorize("hasRole('BACKOFFICE')")
    public ResponseEntity<List<ApiKeyResponse>> getMyApiKeys(@AuthenticationPrincipal Jwt jwt) {
        // Retourne les API Keys associées à ce keycloakId
        return ResponseEntity.ok(apiKeyService.findByKeycloakId(jwt.getSubject()));
    }
}
```

---

### Lier ApiKey à un utilisateur Keycloak

Mettre à jour l'entité `ApiKey` pour tracer le créateur :

```java
// Ajout dans ApiKey.java
- String createdByKeycloakId — nullable (null si créée via un autre moyen)
```

Mettre à jour `ApiKeyService.create()` pour recevoir le `keycloakId` du JWT et le stocker.

Nouvelle méthode `ApiKeyRepository` :
```java
List<ApiKey> findByCreatedByKeycloakIdAndActiveTrue(String keycloakId);
```

---

### Mise à jour des routes `SecurityConfig` — règles finales

```
/api/account/**                    → JWT Keycloak uniquement (hasRole USER ou BACKOFFICE)
/api/account/me/api-keys           → JWT Keycloak + hasRole BACKOFFICE
/api/api-keys/**                   → API Key BACKOFFICE OU JWT BACKOFFICE
/api/charts/**  (GET)              → API Key ou JWT (authenticated)
/api/charts/**  (POST/PUT/DELETE)  → API Key BACKOFFICE OU JWT BACKOFFICE
/api/events/**  (GET)              → API Key ou JWT (authenticated)
/api/events/**  (POST/PUT/DELETE)  → API Key BACKOFFICE OU JWT BACKOFFICE
/api/events/*/hold|book|release    → API Key PUBLIC/BO OU JWT USER/BO
/api/public/**                     → Session Token uniquement
/actuator/health                   → permitAll
```

---

### Mise à jour de la structure des packages

```
src/main/java/com/seating/
├── controller/
│   └── AccountController.java          ← NOUVEAU
├── model/
│   └── UserProfile.java                ← NOUVEAU
├── repository/
│   └── UserProfileRepository.java      ← NOUVEAU
│       findByKeycloakId(String id)
├── service/
│   └── UserSyncService.java            ← NOUVEAU
└── dto/
    ├── AccountResponse.java            ← NOUVEAU
    └── AccountUpdateRequest.java       ← NOUVEAU
```

---

### Mise à jour de la structure Docker

```
seating-app/
├── infra/
│   ├── postgres/
│   │   └── init.sql                    ← crée la DB keycloak
│   └── keycloak/
│       └── realm-seating.json          ← import du realm au démarrage
├── backend/
│   └── Dockerfile
├── frontend/
│   └── Dockerfile
└── docker-compose.yml
```


---

## Consignes pour l'IA de l'IDE

1. Générer **tous les fichiers listés** dans la structure ci-dessus.
   — Ajouter `BCryptPasswordEncoder` comme bean Spring dans `SecurityConfig` ou une `@Configuration` dédiée.
   — `ApiKeyService` injecte ce bean pour encoder et vérifier les secrets.
2. Utiliser **Lombok** (`@Data`, `@Builder`, `@NoArgsConstructor`, `@AllArgsConstructor`, `@RequiredArgsConstructor`) sur toutes les entités et DTOs.
3. Utiliser `@JsonType` de hypersistence-utils pour le champ `objectsJson` de `Chart`.
4. `ChartObjectNode` doit être un POJO simple avec `@JsonIgnoreProperties(ignoreUnknown = true)`.
5. `BookingService` doit utiliser `StringRedisTemplate` (pas `RedisTemplate<Object,Object>`).
6. Toutes les réponses d'erreur doivent utiliser `ErrorResponse { String error; int status; LocalDateTime timestamp; }`.
7. Les controllers doivent retourner `ResponseEntity<?>` avec les bons codes HTTP.
8. Ajouter `@CrossOrigin(origins = "*")` sur tous les controllers (dev) ou configurer un `CorsFilter` global.
9. `HoldExpirationJob` doit être un `@Component` avec `@Scheduled`.
10. `SeatingApplication` doit porter `@EnableScheduling`.

---

## Session publique & widget embarquable

### Concept

L'utilisateur final (visiteur du site client) ne doit **jamais** voir les API Keys dans le navigateur.
Le flow repose sur un **token de session court-vivant** (JWT signé, TTL 1h) que le serveur du client
obtient via notre API et transmet à son frontend. Ce token donne accès en lecture + booking à
un seul événement précis.

```
Serveur client (backend)          Notre API
  POST /api/sessions  ──────────────────────▶  génère JWT signé { eventId, scope: PUBLIC, exp: +1h }
  ◀─────────────────────────────────────────   { sessionToken: "eyJ..." }

  → Transmet le sessionToken à son frontend (cookie httpOnly ou réponse JSON)

Navigateur visiteur               Notre API
  GET /api/public/events/{id}?token=eyJ...  ──▶  valide JWT, retourne chart + statuts
  POST /api/public/events/{id}/hold?token=  ──▶  hold sièges
  POST /api/public/events/{id}/book?token=  ──▶  confirmation
```

---

### Entité / DTO `Session`

Pas d'entité DB — le token est un **JWT stateless** signé par notre API avec une clé secrète serveur.

**Payload JWT :**
```json
{
  "eventId": "uuid-de-l-event",
  "keyId":   "pk_pub_a3f9c2d1",
  "scope":   "PUBLIC",
  "iat":     1718000000,
  "exp":     1718003600
}
```

Ajouter la dépendance JWT :
```xml
<dependency>
  <groupId>io.jsonwebtoken</groupId>
  <artifactId>jjwt-api</artifactId>
  <version>0.12.6</version>
</dependency>
<dependency>
  <groupId>io.jsonwebtoken</groupId>
  <artifactId>jjwt-impl</artifactId>
  <version>0.12.6</version>
  <scope>runtime</scope>
</dependency>
<dependency>
  <groupId>io.jsonwebtoken</groupId>
  <artifactId>jjwt-jackson</artifactId>
  <version>0.12.6</version>
  <scope>runtime</scope>
</dependency>
```

Ajouter dans `application.yml` :
```yaml
seating:
  jwt-secret: "une-clé-secrète-base64-256bits-minimum-à-changer-en-prod"
  session-duration-minutes: 60
```

---

### DTO `SessionRequest` / `SessionResponse`

```java
// SessionRequest — corps du POST /api/sessions (authentifié par API Key PUBLIC ou BACKOFFICE)
{ UUID eventId; }

// SessionResponse — retourné au serveur client
{ String sessionToken; UUID eventId; LocalDateTime expiresAt; }
```

---

### Service `SessionService`

```
createSession(UUID eventId, String keyId):
  1. Vérifier que l'event existe (lève ResourceNotFoundException sinon)
  2. Construire le JWT avec JJWT :
       - subject = keyId
       - claim "eventId" = eventId.toString()
       - claim "scope" = "PUBLIC"
       - issuedAt = now()
       - expiration = now() + sessionDurationMinutes
       - signer avec HS256 + la clé secrète de application.yml
  3. Retourner SessionResponse { sessionToken, eventId, expiresAt }

validateSession(String token) → SessionContext { UUID eventId, String keyId }:
  1. Parser et vérifier la signature JWT (lève UnauthorizedException si invalide ou expiré)
  2. Extraire eventId et keyId
  3. Retourner SessionContext
```

---

### Contrôleur `SessionController` — `/api/sessions`

- `POST /` → **BACKOFFICE ou PUBLIC** (API Key requise)
  - Corps : `{ eventId }`
  - Extrait le `keyId` du `SecurityContext` (mis par le filtre ApiKey)
  - Retourne `SessionResponse`

---

### Contrôleur `PublicEventController` — `/api/public/events/{eventId}`

Routes **sans API Key**, protégées uniquement par le `sessionToken` en query param ou header `X-Session-Token`.

Un `SessionTokenFilter` (second filtre, après `ApiKeyAuthFilter`) intercepte ces routes :
1. Lire `X-Session-Token` (header) ou `?token=` (query param)
2. Appeler `SessionService.validateSession(token)`
3. Vérifier que `SessionContext.eventId` correspond au `{eventId}` de la route (403 sinon)
4. Injecter un `Authentication` avec authority `ROLE_SESSION`

Endpoints :
- `GET /{eventId}` → retourne `EventDetailResponse` (chart complet + statuts de tous les sièges)
  — même structure que `GET /api/events/{id}` mais accessible avec session token
- `POST /{eventId}/hold` → `HoldRequest` — délègue à `BookingService.holdSeats()`
- `POST /{eventId}/book` → `BookRequest` — délègue à `BookingService.bookSeats()`
- `POST /{eventId}/release` → `ReleaseRequest` — délègue à `BookingService.releaseSeats()`

---

### Mise à jour `SecurityConfig`

Ajouter `SessionTokenFilter` dans la chaîne, après `ApiKeyAuthFilter` :
- Routes `/api/public/**` → ne pas appliquer `ApiKeyAuthFilter`, appliquer `SessionTokenFilter`
- Routes `/api/sessions` → appliquer `ApiKeyAuthFilter` normalement
- `ROLE_SESSION` autorisé sur toutes les routes `/api/public/**`

---

### Mise à jour de la structure des packages

```
src/main/java/com/seating/
├── controller/
│   ├── SessionController.java          ← NOUVEAU
│   └── PublicEventController.java      ← NOUVEAU
├── service/
│   └── SessionService.java             ← NOUVEAU
├── dto/
│   ├── SessionRequest.java             ← NOUVEAU
│   ├── SessionResponse.java            ← NOUVEAU
│   └── SessionContext.java             ← NOUVEAU (record : UUID eventId, String keyId)
└── filter/
    └── SessionTokenFilter.java         ← NOUVEAU
```

---

### Widget JS embarquable (Vue compilé)

Le frontend Vue expose un **web component** `<seating-map>` compilé en un seul fichier `widget.js`.

**Intégration côté site client :**
```html
<!-- 1. Charger le widget depuis notre CDN -->
<script src="https://notre-app.com/widget.js"></script>

<!-- 2. Placer le composant dans la page -->
<seating-map
  event-id="uuid-de-l-event"
  session-token="eyJ..."
  api-base-url="https://notre-api.com"
  lang="fr"
></seating-map>

<script>
  // 3. Écouter les événements du widget
  document.querySelector('seating-map')
    .addEventListener('seating:booked', (e) => {
      // e.detail = { seatKeys: ["A1","A2"], holdToken: "xxx" }
      // Appeler son propre backend pour finaliser la commande
      fetch('/mon-checkout', {
        method: 'POST',
        body: JSON.stringify(e.detail)
      });
    });
</script>
```

**Événements émis par le widget :**
- `seating:selected` → `{ seatKeys, totalPrice }`
- `seating:hold` → `{ seatKeys, holdToken, expiresAt }`
- `seating:booked` → `{ seatKeys, holdToken }`
- `seating:error` → `{ message, code }`

**Attributs du web component :**
| Attribut | Obligatoire | Description |
|---|---|---|
| `event-id` | oui | UUID de l'événement |
| `session-token` | oui | JWT obtenu via `POST /api/sessions` |
| `api-base-url` | oui | URL de base de notre API |
| `lang` | non | `fr` ou `en` (défaut `fr`) |
| `max-seats` | non | Nombre max de sièges sélectionnables |
| `hold-on-select` | non | `true` = hold automatique à la sélection |

**Build Vue pour le widget :**
```js
// vite.config.widget.js
export default defineConfig({
  build: {
    lib: {
      entry: 'src/widget/index.js',
      name: 'SeatingWidget',
      fileName: 'widget',
      formats: ['iife']  // un seul fichier JS auto-exécutable
    },
    rollupOptions: {
      output: { inlineDynamicImports: true }
    }
  }
})
```

```js
// src/widget/index.js
import { defineCustomElement } from 'vue'
import SeatingMap from './SeatingMap.ce.vue'  // .ce.vue = Custom Element mode

const SeatingMapElement = defineCustomElement(SeatingMap)
customElements.define('seating-map', SeatingMapElement)
```

---

### Consignes supplémentaires pour l'IA de l'IDE

11. Ajouter `SessionController` et `PublicEventController` avec les routes décrites.
12. `SessionService` utilise `io.jsonwebtoken` (JJWT 0.12.x) avec l'API fluente `Jwts.builder()`.
13. `SessionTokenFilter` étend `OncePerRequestFilter`, s'applique uniquement aux routes `/api/public/**`.
14. Le `SecurityConfig` doit exclure `/api/public/**` du filtre `ApiKeyAuthFilter` et y appliquer `SessionTokenFilter`.
15. `SessionContext` est un Java `record` : `record SessionContext(UUID eventId, String keyId) {}`.
16. La clé JWT est lue depuis `application.yml` via `@Value("${seating.jwt-secret}")` et convertie en `SecretKey` avec `Keys.hmacShaKeyFor(Base64.getDecoder().decode(secret))`.
17. `BookingService.holdSeats()` doit toujours dédupliquer la liste de seatKeys en premier (`stream().distinct().toList()`).
18. Le pipeline Redis pour le hold doit utiliser `redisTemplate.executePipelined()` pour l'atomicité des écritures.
19. `SeatNotAvailableException` doit porter un champ `List<SeatConflictDetail> conflicts` pour lister précisément les sièges en conflit — le `GlobalExceptionHandler` la sérialise en `SeatConflictResponse` avec HTTP 409.
20. `HoldExpirationJob` doit utiliser `@Transactional` et effectuer un `saveAll()` batch plutôt qu'une boucle de `save()` individuels.
21. Lors du `holdSeats`, si un siège est en HOLD avec le même holdToken → rafraîchir le TTL Redis sans erreur (renouvellement idempotent).
22. Lors du `holdSeats` passe 1, si la clé Redis est absente mais le statut DB est HOLD avec `heldUntil < now()` → traiter ce siège comme AVAILABLE (expiration en cours).

---

## Synchronisation temps réel — WebSocket + STOMP

### Pourquoi

Sans push server, les changements de statut (hold, book, release, expiration) ne sont visibles
par les autres clients que lors de leur prochain appel `GET /seats`. Dans une salle avec
plusieurs acheteurs simultanés, un siège peut sembler disponible alors qu'il vient d'être booké.

Le WebSocket STOMP permet de diffuser instantanément chaque changement à tous les clients
abonnés à un événement donné.

---

### Dépendance

```xml
<dependency>
  <groupId>org.springframework.boot</groupId>
  <artifactId>spring-boot-starter-websocket</artifactId>
</dependency>
```

---

### `WebSocketConfig`

```java
@Configuration
@EnableWebSocketMessageBroker
public class WebSocketConfig implements WebSocketMessageBrokerConfigurer {

    @Override
    public void configureMessageBroker(MessageBrokerRegistry registry) {
        // Préfixe des topics de diffusion (server → clients)
        registry.enableSimpleBroker("/topic");
        // Préfixe des destinations côté client (client → server)
        registry.setApplicationDestinationPrefixes("/app");
    }

    @Override
    public void registerStompEndpoints(StompEndpointRegistry registry) {
        registry.addEndpoint("/ws")
                .setAllowedOriginPatterns("*")   // restreindre en prod
                .withSockJS();                   // fallback pour navigateurs sans WS natif
    }
}
```

**Topic par événement :**
```
/topic/events/{eventId}/seats
```
Chaque client s'abonne au topic de l'événement qu'il consulte.
Il ne reçoit que les changements qui le concernent.

---

### DTO `SeatStatusChangedEvent`

Message envoyé sur le WebSocket à chaque changement de statut :

```java
// Envoyé en JSON sur /topic/events/{eventId}/seats
SeatStatusChangedEvent {
    UUID        eventId;
    String      seatKey;       // clé du siège concerné
    SeatStatus  newStatus;     // AVAILABLE | HOLD | BOOKED | CANCELED
    String      holdToken;     // présent uniquement si newStatus = HOLD (pour que
                               // le propriétaire du hold sache que c'est lui)
    LocalDateTime changedAt;
    String      triggeredBy;   // "user" | "expiration" | "backoffice"
}
```

Pour les messages de type `HOLD`, le `holdToken` est inclus pour que le client
qui vient de poser le hold puisse reconnaître ses propres sièges.
Pour les autres clients, le `holdToken` permet de savoir que le siège est pris
sans révéler qui le tient.

---

### Service `SeatEventPublisher`

Bean Spring qui centralise l'envoi des messages WebSocket.
Injecté dans `BookingService` et `HoldExpirationJob`.

```java
@Service
@RequiredArgsConstructor
public class SeatEventPublisher {

    private final SimpMessagingTemplate messagingTemplate;

    public void publishChange(UUID eventId, String seatKey, SeatStatus newStatus,
                              String holdToken, String triggeredBy) {
        SeatStatusChangedEvent event = SeatStatusChangedEvent.builder()
            .eventId(eventId)
            .seatKey(seatKey)
            .newStatus(newStatus)
            .holdToken(holdToken)
            .changedAt(LocalDateTime.now())
            .triggeredBy(triggeredBy)
            .build();

        messagingTemplate.convertAndSend(
            "/topic/events/" + eventId + "/seats",
            event
        );
    }

    // Surcharge pour plusieurs sièges d'un coup
    public void publishChanges(UUID eventId, List<String> seatKeys,
                               SeatStatus newStatus, String holdToken, String triggeredBy) {
        seatKeys.forEach(key ->
            publishChange(eventId, key, newStatus, holdToken, triggeredBy)
        );
    }
}
```

---

### Mise à jour `BookingService` — ajout des publishes

Après chaque modification de statut en DB, appeler `SeatEventPublisher` :

```
holdSeats(...) :
  ... (logique existante) ...
  seatEventPublisher.publishChanges(eventId, seatKeys, HOLD, holdToken, "user")

bookSeats(...) :
  ... (logique existante) ...
  seatEventPublisher.publishChanges(eventId, seatKeys, BOOKED, null, "user")

releaseSeats(...) :
  ... (logique existante) ...
  seatEventPublisher.publishChanges(eventId, seatKeys, AVAILABLE, null, "user")

changeStatus(...) :
  ... (logique existante) ...
  seatEventPublisher.publishChanges(eventId, seatKeys, newStatus, null, "backoffice")
```

---

### Mise à jour `HoldExpirationJob` — publish des expirations

```
expireHolds() :
  ... (logique existante) ...
  // Après saveAll() et nettoyage Redis :
  // Grouper les sièges expirés par eventId et publier
  expiredSeats.stream()
    .collect(groupingBy(s -> s.getEvent().getId()))
    .forEach((eventId, seats) -> {
        List<String> keys = seats.stream().map(EventSeat::getSeatKey).toList();
        seatEventPublisher.publishChanges(eventId, keys, AVAILABLE, null, "expiration");
    });
```

---

### Sécurité WebSocket

Le handshake WebSocket initial doit être authentifié.
Ajouter dans `WebSocketConfig` un `ChannelInterceptor` :

```java
@Override
public void configureClientInboundChannel(ChannelRegistration registration) {
    registration.interceptors(new ChannelInterceptor() {
        @Override
        public Message<?> preSend(Message<?> message, MessageChannel channel) {
            StompHeaderAccessor accessor = StompHeaderAccessor.wrap(message);

            if (StompCommand.CONNECT.equals(accessor.getCommand())) {
                // Lire le token depuis le header STOMP "X-Session-Token" ou "Authorization"
                String sessionToken = accessor.getFirstNativeHeader("X-Session-Token");
                String bearerToken  = accessor.getFirstNativeHeader("Authorization");

                if (sessionToken != null) {
                    // Valider le session token (SessionService.validateSession)
                    // Injecter le SessionContext dans les attributs de la session WS
                    SessionContext ctx = sessionService.validateSession(sessionToken);
                    accessor.getSessionAttributes().put("sessionContext", ctx);
                } else if (bearerToken != null && bearerToken.startsWith("Bearer ")) {
                    // Valider le JWT Keycloak
                    // Injecter les infos utilisateur dans la session WS
                    accessor.getSessionAttributes().put("authenticated", true);
                } else {
                    throw new MessagingException("Authentification WebSocket requise");
                }
            }
            return message;
        }
    });
}
```

---

### Endpoint WebSocket dans `SecurityConfig`

```java
// Autoriser le handshake WS et SockJS sans filtre API Key
.requestMatchers("/ws/**").permitAll()
// La sécurité est gérée par le ChannelInterceptor STOMP
```

---

### Mise à jour `nginx.conf` — proxy WebSocket

```nginx
# WebSocket — upgrade de connexion requis
location /ws/ {
    proxy_pass         http://backend:8080/ws/;
    proxy_http_version 1.1;
    proxy_set_header   Upgrade    $http_upgrade;
    proxy_set_header   Connection "upgrade";
    proxy_set_header   Host       $host;
    proxy_read_timeout 3600s;     # garder la connexion WS ouverte 1h
    proxy_send_timeout 3600s;
}
```

---

### Intégration côté widget Vue

Le widget `<seating-map>` se connecte au WebSocket à l'initialisation et met à jour
le plan en temps réel sans rechargement :

```javascript
// src/widget/useSeatingSocket.js
import { Client } from '@stomp/stompjs'
import SockJS from 'sockjs-client'

export function useSeatingSocket(apiBaseUrl, eventId, sessionToken, onSeatChange) {
  const client = new Client({
    webSocketFactory: () => new SockJS(`${apiBaseUrl}/ws`),
    connectHeaders: { 'X-Session-Token': sessionToken },
    onConnect: () => {
      client.subscribe(`/topic/events/${eventId}/seats`, (message) => {
        const change = JSON.parse(message.body)
        onSeatChange(change)  // { seatKey, newStatus, holdToken, changedAt }
      })
    },
    reconnectDelay: 5000,    // reconnexion automatique après 5s
  })
  client.activate()
  return () => client.deactivate()   // fonction de cleanup
}
```

Dépendances npm à ajouter au frontend :
```bash
npm install @stomp/stompjs sockjs-client
```

---

### Mise à jour de la structure des packages

```
src/main/java/com/seating/
├── config/
│   └── WebSocketConfig.java          ← NOUVEAU
├── service/
│   └── SeatEventPublisher.java       ← NOUVEAU
└── dto/
    └── SeatStatusChangedEvent.java   ← NOUVEAU
```

---

### Consignes supplémentaires pour l'IA de l'IDE

37. Ajouter `spring-boot-starter-websocket` dans `pom.xml`.
38. `WebSocketConfig` implémente `WebSocketMessageBrokerConfigurer` avec `@EnableWebSocketMessageBroker`.
39. Le endpoint STOMP est `/ws` avec fallback SockJS.
40. `SeatEventPublisher` injecte `SimpMessagingTemplate` et publie sur `/topic/events/{eventId}/seats`.
41. `BookingService` injecte `SeatEventPublisher` et appelle `publishChanges()` après chaque modification DB réussie — **après** le commit de la transaction (`@TransactionalEventListener` ou appel post-commit).
42. `HoldExpirationJob` groupe les sièges expirés par `eventId` avant de publier pour minimiser le nombre d'appels WebSocket.
43. Le `ChannelInterceptor` valide le token STOMP au `CONNECT` uniquement — ne pas re-valider à chaque message.
44. La route `/ws/**` est exclue du filtre `ApiKeyAuthFilter` dans `SecurityConfig`.
45. Dans `nginx.conf`, le bloc `/ws/` doit inclure `proxy_set_header Upgrade $http_upgrade` et `Connection "upgrade"` — sans ces headers, le proxy Nginx coupe le WebSocket.


---

## Documentation API — Swagger / OpenAPI 3

### Dépendance

```xml
<dependency>
  <groupId>org.springdoc</groupId>
  <artifactId>springdoc-openapi-starter-webmvc-ui</artifactId>
  <version>2.5.0</version>
</dependency>
```

### URLs d'accès

| URL | Description |
|---|---|
| `http://localhost:8080/swagger-ui.html` | Interface Swagger UI interactive |
| `http://localhost:8080/v3/api-docs` | Spec OpenAPI 3 en JSON |
| `http://localhost:8080/v3/api-docs.yaml` | Spec OpenAPI 3 en YAML |

### `OpenApiConfig`

```java
@Configuration
public class OpenApiConfig {

    @Bean
    public OpenAPI placioOpenAPI() {
        return new OpenAPI()
            .info(new Info()
                .title("Placio API")
                .description("API de gestion de réservation de sièges — plateforme Placio")
                .version("1.0.0")
                .contact(new Contact()
                    .name("Placio")
                    .email("api@placio.io")))
            .addSecurityItem(new SecurityRequirement()
                .addList("ApiKey")
                .addList("BearerAuth"))
            .components(new Components()
                .addSecuritySchemes("ApiKey",
                    new SecurityScheme()
                        .type(SecurityScheme.Type.APIKEY)
                        .in(SecurityScheme.In.HEADER)
                        .name("X-Api-Key-Id")
                        .description("Identifiant de la clé API (pk_bo_... ou pk_pub_...)"))
                .addSecuritySchemes("ApiKeySecret",
                    new SecurityScheme()
                        .type(SecurityScheme.Type.APIKEY)
                        .in(SecurityScheme.In.HEADER)
                        .name("X-Api-Key-Secret")
                        .description("Secret de la clé API (sk_bo_... ou sk_pub_...)"))
                .addSecuritySchemes("BearerAuth",
                    new SecurityScheme()
                        .type(SecurityScheme.Type.HTTP)
                        .scheme("bearer")
                        .bearerFormat("JWT")
                        .description("JWT Keycloak — obtenu via le realm seating"))
                .addSecuritySchemes("SessionToken",
                    new SecurityScheme()
                        .type(SecurityScheme.Type.APIKEY)
                        .in(SecurityScheme.In.HEADER)
                        .name("X-Session-Token")
                        .description("Token de session court-vivant pour les routes /api/public/**")));
    }
}
```

### Annotations sur les controllers

Chaque controller doit être annoté avec `@Tag` et chaque endpoint avec `@Operation` + `@ApiResponses`.

**Exemple complet — `BookingController` :**

```java
@RestController
@RequestMapping("/api/events/{eventId}")
@Tag(name = "Booking", description = "Gestion des réservations de sièges")
public class BookingController {

    @PostMapping("/hold")
    @Operation(
        summary = "Poser un hold sur des sièges",
        description = "Réserve temporairement des sièges pendant 10 minutes. " +
                      "Accessible avec une API Key PUBLIC/BACKOFFICE ou un session token.",
        security = { @SecurityRequirement(name = "ApiKey"),
                     @SecurityRequirement(name = "SessionToken") }
    )
    @ApiResponses({
        @ApiResponse(responseCode = "200", description = "Hold posé avec succès",
            content = @Content(schema = @Schema(implementation = HoldResponse.class))),
        @ApiResponse(responseCode = "409", description = "Un ou plusieurs sièges non disponibles",
            content = @Content(schema = @Schema(implementation = SeatConflictResponse.class))),
        @ApiResponse(responseCode = "401", description = "Authentification requise"),
        @ApiResponse(responseCode = "404", description = "Événement ou siège introuvable")
    })
    public ResponseEntity<HoldResponse> holdSeats(
            @PathVariable @Parameter(description = "UUID de l'événement") UUID eventId,
            @Valid @RequestBody HoldRequest request) {
        return ResponseEntity.ok(bookingService.holdSeats(eventId,
            request.getSeatKeys(), request.getHoldToken()));
    }
}
```

**Tags à appliquer par controller :**

| Controller | @Tag name |
|---|---|
| `ApiKeyController` | `"API Keys"` |
| `CategoryController` | `"Catégories"` |
| `ChartController` | `"Plans de salle (Charts)"` |
| `EventController` | `"Événements"` |
| `BookingController` | `"Booking"` |
| `SessionController` | `"Sessions publiques"` |
| `PublicEventController` | `"Mode public"` |
| `AccountController` | `"Compte utilisateur"` |

### Exclure Swagger en production

Dans `application-prod.yml` :
```yaml
springdoc:
  api-docs:
    enabled: false
  swagger-ui:
    enabled: false
```

Dans `application.yml` (dev) :
```yaml
springdoc:
  api-docs:
    path: /v3/api-docs
  swagger-ui:
    path: /swagger-ui.html
    operations-sorter: alpha
    tags-sorter: alpha
    display-request-duration: true
    try-it-out-enabled: true
  show-actuator: false
```

### Autoriser Swagger dans `SecurityConfig`

```java
.requestMatchers(
    "/swagger-ui.html",
    "/swagger-ui/**",
    "/v3/api-docs/**",
    "/v3/api-docs.yaml"
).permitAll()
```

---

## Tests unitaires et d'intégration

### Dépendances de test

```xml
<dependency>
  <groupId>org.springframework.boot</groupId>
  <artifactId>spring-boot-starter-test</artifactId>
  <scope>test</scope>
  <!-- inclut JUnit 5, Mockito, AssertJ, MockMvc -->
</dependency>
<dependency>
  <groupId>org.springframework.security</groupId>
  <artifactId>spring-security-test</artifactId>
  <scope>test</scope>
</dependency>
<dependency>
  <groupId>com.h2database</groupId>
  <artifactId>h2</artifactId>
  <scope>test</scope>
</dependency>
```

### Structure des tests

```
src/test/java/com/seating/
├── controller/
│   ├── ChartControllerTest.java
│   ├── EventControllerTest.java
│   ├── BookingControllerTest.java
│   ├── ApiKeyControllerTest.java
│   ├── AccountControllerTest.java
│   └── PublicEventControllerTest.java
├── service/
│   ├── BookingServiceTest.java
│   ├── ApiKeyServiceTest.java
│   ├── EventServiceTest.java
│   ├── ChartServiceTest.java
│   └── HoldExpirationJobTest.java
├── filter/
│   └── ApiKeyAuthFilterTest.java
└── integration/
    ├── BookingFlowIntegrationTest.java
    └── SessionFlowIntegrationTest.java
```

---

### Tests de service — `BookingServiceTest`

```java
@ExtendWith(MockitoExtension.class)
class BookingServiceTest {

    @Mock EventSeatRepository eventSeatRepository;
    @Mock StringRedisTemplate redisTemplate;
    @Mock ValueOperations<String, String> valueOps;
    @Mock SeatEventPublisher seatEventPublisher;

    @InjectMocks BookingService bookingService;

    private UUID eventId;
    private EventSeat availableSeat;

    @BeforeEach
    void setUp() {
        eventId = UUID.randomUUID();
        availableSeat = EventSeat.builder()
            .id(UUID.randomUUID())
            .seatKey("A1")
            .status(SeatStatus.AVAILABLE)
            .build();
        when(redisTemplate.opsForValue()).thenReturn(valueOps);
    }

    @Test
    @DisplayName("holdSeats — succès sur siège disponible")
    void holdSeats_success() {
        when(eventSeatRepository.findByEventIdAndSeatKeyIn(eventId, List.of("A1")))
            .thenReturn(List.of(availableSeat));
        when(valueOps.setIfAbsent(anyString(), anyString(), any(Duration.class)))
            .thenReturn(true);

        HoldResponse response = bookingService.holdSeats(eventId, List.of("A1"), "token-123");

        assertThat(response.getSeatKeys()).containsExactly("A1");
        assertThat(response.getHoldToken()).isEqualTo("token-123");
        assertThat(availableSeat.getStatus()).isEqualTo(SeatStatus.HOLD);
        verify(seatEventPublisher).publishChanges(eventId, List.of("A1"), SeatStatus.HOLD, "token-123", "user");
    }

    @Test
    @DisplayName("holdSeats — déduplique les seatKeys en entrée")
    void holdSeats_deduplicatesSeatKeys() {
        when(eventSeatRepository.findByEventIdAndSeatKeyIn(eventId, List.of("A1")))
            .thenReturn(List.of(availableSeat));
        when(valueOps.setIfAbsent(anyString(), anyString(), any(Duration.class)))
            .thenReturn(true);

        bookingService.holdSeats(eventId, List.of("A1", "A1", "A1"), "token-123");

        verify(eventSeatRepository).findByEventIdAndSeatKeyIn(eventId, List.of("A1"));
    }

    @Test
    @DisplayName("holdSeats — lève SeatNotAvailableException si siège déjà en HOLD par un autre token")
    void holdSeats_throwsWhenAlreadyHeld() {
        availableSeat.setStatus(SeatStatus.HOLD);
        availableSeat.setHoldToken("autre-token");
        when(eventSeatRepository.findByEventIdAndSeatKeyIn(eventId, List.of("A1")))
            .thenReturn(List.of(availableSeat));

        assertThatThrownBy(() ->
            bookingService.holdSeats(eventId, List.of("A1"), "mon-token"))
            .isInstanceOf(SeatNotAvailableException.class)
            .hasMessageContaining("A1");
    }

    @Test
    @DisplayName("holdSeats — renouvelle silencieusement si même token")
    void holdSeats_renewsIfSameToken() {
        availableSeat.setStatus(SeatStatus.HOLD);
        availableSeat.setHoldToken("token-123");
        availableSeat.setHeldUntil(LocalDateTime.now().plusMinutes(5));
        when(eventSeatRepository.findByEventIdAndSeatKeyIn(eventId, List.of("A1")))
            .thenReturn(List.of(availableSeat));
        when(valueOps.setIfAbsent(anyString(), anyString(), any(Duration.class)))
            .thenReturn(true);

        assertThatNoException().isThrownBy(() ->
            bookingService.holdSeats(eventId, List.of("A1"), "token-123"));
    }

    @Test
    @DisplayName("bookSeats — succès si tous les sièges en HOLD avec le bon token")
    void bookSeats_success() {
        availableSeat.setStatus(SeatStatus.HOLD);
        availableSeat.setHoldToken("token-123");
        when(eventSeatRepository.findByEventIdAndSeatKeyIn(eventId, List.of("A1")))
            .thenReturn(List.of(availableSeat));

        BookResponse response = bookingService.bookSeats(eventId, List.of("A1"), "token-123");

        assertThat(response.getBookedSeats()).containsExactly("A1");
        assertThat(availableSeat.getStatus()).isEqualTo(SeatStatus.BOOKED);
        assertThat(availableSeat.getHoldToken()).isNull();
        verify(seatEventPublisher).publishChanges(eventId, List.of("A1"), SeatStatus.BOOKED, null, "user");
    }

    @Test
    @DisplayName("bookSeats — lève exception si holdToken ne correspond pas")
    void bookSeats_throwsIfWrongToken() {
        availableSeat.setStatus(SeatStatus.HOLD);
        availableSeat.setHoldToken("autre-token");
        when(eventSeatRepository.findByEventIdAndSeatKeyIn(eventId, List.of("A1")))
            .thenReturn(List.of(availableSeat));

        assertThatThrownBy(() ->
            bookingService.bookSeats(eventId, List.of("A1"), "mon-token"))
            .isInstanceOf(SeatNotAvailableException.class);
    }

    @Test
    @DisplayName("releaseSeats — remet le siège en AVAILABLE")
    void releaseSeats_success() {
        availableSeat.setStatus(SeatStatus.HOLD);
        availableSeat.setHoldToken("token-123");
        when(eventSeatRepository.findByEventIdAndSeatKeyIn(eventId, List.of("A1")))
            .thenReturn(List.of(availableSeat));

        bookingService.releaseSeats(eventId, List.of("A1"), "token-123");

        assertThat(availableSeat.getStatus()).isEqualTo(SeatStatus.AVAILABLE);
        assertThat(availableSeat.getHoldToken()).isNull();
    }
}
```

---

### Tests de service — `ApiKeyServiceTest`

```java
@ExtendWith(MockitoExtension.class)
class ApiKeyServiceTest {

    @Mock ApiKeyRepository apiKeyRepository;
    @Mock BCryptPasswordEncoder passwordEncoder;
    @InjectMocks ApiKeyService apiKeyService;

    @Test
    @DisplayName("create — génère keyId et secret avec bon préfixe BACKOFFICE")
    void create_generatesBackofficeKey() {
        when(passwordEncoder.encode(anyString())).thenReturn("$2a$hashed");
        when(apiKeyRepository.save(any())).thenAnswer(i -> i.getArgument(0));

        ApiKeyCreatedResponse response = apiKeyService.create(
            new ApiKeyRequest("Ma clé BO", ApiKeyScope.BACKOFFICE));

        assertThat(response.getKeyId()).startsWith("pk_bo_");
        assertThat(response.getSecret()).startsWith("sk_bo_");
        assertThat(response.getSecret()).hasSizeGreaterThan(20);
    }

    @Test
    @DisplayName("create — génère préfixe PUBLIC correct")
    void create_generatesPublicKey() {
        when(passwordEncoder.encode(anyString())).thenReturn("$2a$hashed");
        when(apiKeyRepository.save(any())).thenAnswer(i -> i.getArgument(0));

        ApiKeyCreatedResponse response = apiKeyService.create(
            new ApiKeyRequest("Ma clé pub", ApiKeyScope.PUBLIC));

        assertThat(response.getKeyId()).startsWith("pk_pub_");
        assertThat(response.getSecret()).startsWith("sk_pub_");
    }

    @Test
    @DisplayName("validate — succès avec bon secret")
    void validate_successWithCorrectSecret() {
        ApiKey key = ApiKey.builder()
            .keyId("pk_bo_abc123")
            .secretHash("$2a$hashed")
            .scope(ApiKeyScope.BACKOFFICE)
            .active(true)
            .build();
        when(apiKeyRepository.findByKeyIdAndActiveTrue("pk_bo_abc123"))
            .thenReturn(Optional.of(key));
        when(passwordEncoder.matches("raw-secret", "$2a$hashed")).thenReturn(true);

        assertThatNoException().isThrownBy(() ->
            apiKeyService.validate("pk_bo_abc123", "raw-secret"));
    }

    @Test
    @DisplayName("validate — lève UnauthorizedException si secret incorrect")
    void validate_throwsIfWrongSecret() {
        ApiKey key = ApiKey.builder()
            .keyId("pk_bo_abc123")
            .secretHash("$2a$hashed")
            .active(true)
            .build();
        when(apiKeyRepository.findByKeyIdAndActiveTrue("pk_bo_abc123"))
            .thenReturn(Optional.of(key));
        when(passwordEncoder.matches("mauvais-secret", "$2a$hashed")).thenReturn(false);

        assertThatThrownBy(() ->
            apiKeyService.validate("pk_bo_abc123", "mauvais-secret"))
            .isInstanceOf(UnauthorizedException.class);
    }

    @Test
    @DisplayName("validate — lève UnauthorizedException si keyId introuvable")
    void validate_throwsIfKeyNotFound() {
        when(apiKeyRepository.findByKeyIdAndActiveTrue(anyString()))
            .thenReturn(Optional.empty());

        assertThatThrownBy(() ->
            apiKeyService.validate("pk_bo_inconnu", "secret"))
            .isInstanceOf(UnauthorizedException.class);
    }
}
```

---

### Tests de service — `HoldExpirationJobTest`

```java
@ExtendWith(MockitoExtension.class)
class HoldExpirationJobTest {

    @Mock EventSeatRepository eventSeatRepository;
    @Mock StringRedisTemplate redisTemplate;
    @Mock SeatEventPublisher seatEventPublisher;
    @InjectMocks HoldExpirationJob holdExpirationJob;

    @Test
    @DisplayName("expireHolds — libère les sièges expirés et publie les changements")
    void expireHolds_releasesExpiredSeats() {
        Event event = Event.builder().id(UUID.randomUUID()).build();
        EventSeat expired1 = EventSeat.builder()
            .id(UUID.randomUUID()).seatKey("A1").status(SeatStatus.HOLD)
            .holdToken("tok-1").heldUntil(LocalDateTime.now().minusMinutes(1))
            .event(event).build();
        EventSeat expired2 = EventSeat.builder()
            .id(UUID.randomUUID()).seatKey("A2").status(SeatStatus.HOLD)
            .holdToken("tok-1").heldUntil(LocalDateTime.now().minusMinutes(1))
            .event(event).build();

        when(eventSeatRepository.findByStatusAndHeldUntilBefore(
                eq(SeatStatus.HOLD), any(LocalDateTime.class)))
            .thenReturn(List.of(expired1, expired2));

        holdExpirationJob.expireHolds();

        assertThat(expired1.getStatus()).isEqualTo(SeatStatus.AVAILABLE);
        assertThat(expired2.getStatus()).isEqualTo(SeatStatus.AVAILABLE);
        assertThat(expired1.getHoldToken()).isNull();
        verify(eventSeatRepository).saveAll(List.of(expired1, expired2));
        verify(seatEventPublisher).publishChanges(
            eq(event.getId()), anyList(), eq(SeatStatus.AVAILABLE), isNull(), eq("expiration"));
    }

    @Test
    @DisplayName("expireHolds — no-op si aucun siège expiré")
    void expireHolds_noOp_whenNoExpiredSeats() {
        when(eventSeatRepository.findByStatusAndHeldUntilBefore(any(), any()))
            .thenReturn(Collections.emptyList());

        holdExpirationJob.expireHolds();

        verify(eventSeatRepository, never()).saveAll(any());
        verify(seatEventPublisher, never()).publishChanges(any(), any(), any(), any(), any());
    }
}
```

---

### Tests de controller — `BookingControllerTest`

```java
@WebMvcTest(BookingController.class)
@Import(SecurityConfig.class)
class BookingControllerTest {

    @Autowired MockMvc mockMvc;
    @Autowired ObjectMapper objectMapper;
    @MockBean BookingService bookingService;
    @MockBean ApiKeyService apiKeyService;

    private static final String KEY_ID = "pk_pub_test123";
    private static final String KEY_SECRET = "sk_pub_secretxyz";

    @BeforeEach
    void setUp() {
        ApiKey mockKey = ApiKey.builder()
            .keyId(KEY_ID).scope(ApiKeyScope.PUBLIC).active(true).build();
        when(apiKeyService.validate(KEY_ID, KEY_SECRET)).thenReturn(mockKey);
    }

    @Test
    @DisplayName("POST /hold — 200 avec sièges disponibles")
    void holdSeats_returns200() throws Exception {
        UUID eventId = UUID.randomUUID();
        HoldRequest request = new HoldRequest(List.of("A1", "A2"), "mon-token");
        HoldResponse response = HoldResponse.builder()
            .holdToken("mon-token")
            .seatKeys(List.of("A1", "A2"))
            .expiresAt(LocalDateTime.now().plusMinutes(10))
            .durationSeconds(600)
            .build();

        when(bookingService.holdSeats(eventId, List.of("A1", "A2"), "mon-token"))
            .thenReturn(response);

        mockMvc.perform(post("/api/events/{eventId}/hold", eventId)
                .header("X-Api-Key-Id", KEY_ID)
                .header("X-Api-Key-Secret", KEY_SECRET)
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(request)))
            .andExpect(status().isOk())
            .andExpect(jsonPath("$.holdToken").value("mon-token"))
            .andExpect(jsonPath("$.seatKeys", hasSize(2)))
            .andExpect(jsonPath("$.durationSeconds").value(600));
    }

    @Test
    @DisplayName("POST /hold — 409 si siège non disponible")
    void holdSeats_returns409WhenConflict() throws Exception {
        UUID eventId = UUID.randomUUID();
        HoldRequest request = new HoldRequest(List.of("A1"), "mon-token");

        when(bookingService.holdSeats(any(), any(), any()))
            .thenThrow(new SeatNotAvailableException("A1", SeatStatus.BOOKED));

        mockMvc.perform(post("/api/events/{eventId}/hold", eventId)
                .header("X-Api-Key-Id", KEY_ID)
                .header("X-Api-Key-Secret", KEY_SECRET)
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(request)))
            .andExpect(status().isConflict())
            .andExpect(jsonPath("$.conflicts[0].seatKey").value("A1"));
    }

    @Test
    @DisplayName("POST /hold — 401 sans credentials")
    void holdSeats_returns401WithoutAuth() throws Exception {
        UUID eventId = UUID.randomUUID();
        HoldRequest request = new HoldRequest(List.of("A1"), "mon-token");

        mockMvc.perform(post("/api/events/{eventId}/hold", eventId)
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(request)))
            .andExpect(status().isUnauthorized());
    }

    @Test
    @DisplayName("POST /hold — 400 si seatKeys vide")
    void holdSeats_returns400WhenEmptySeatKeys() throws Exception {
        UUID eventId = UUID.randomUUID();
        HoldRequest request = new HoldRequest(Collections.emptyList(), "mon-token");

        mockMvc.perform(post("/api/events/{eventId}/hold", eventId)
                .header("X-Api-Key-Id", KEY_ID)
                .header("X-Api-Key-Secret", KEY_SECRET)
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(request)))
            .andExpect(status().isBadRequest());
    }

    @Test
    @DisplayName("POST /book — 200 si hold valide")
    void bookSeats_returns200() throws Exception {
        UUID eventId = UUID.randomUUID();
        BookRequest request = new BookRequest(List.of("A1"), "mon-token");
        BookResponse response = BookResponse.builder()
            .bookedSeats(List.of("A1"))
            .eventId(eventId)
            .bookedAt(LocalDateTime.now())
            .build();

        when(bookingService.bookSeats(eventId, List.of("A1"), "mon-token"))
            .thenReturn(response);

        mockMvc.perform(post("/api/events/{eventId}/book", eventId)
                .header("X-Api-Key-Id", KEY_ID)
                .header("X-Api-Key-Secret", KEY_SECRET)
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(request)))
            .andExpect(status().isOk())
            .andExpect(jsonPath("$.bookedSeats[0]").value("A1"));
    }

    @Test
    @DisplayName("GET /seats — 200 avec liste des statuts")
    void getSeats_returns200() throws Exception {
        UUID eventId = UUID.randomUUID();
        EventDetailResponse detail = EventDetailResponse.builder()
            .id(eventId)
            .seats(List.of(
                new EventSeatStatusDto("A1", SeatStatus.AVAILABLE),
                new EventSeatStatusDto("A2", SeatStatus.BOOKED)
            )).build();

        when(bookingService.getSeats(eventId)).thenReturn(detail.getSeats());

        mockMvc.perform(get("/api/events/{eventId}/seats", eventId)
                .header("X-Api-Key-Id", KEY_ID)
                .header("X-Api-Key-Secret", KEY_SECRET))
            .andExpect(status().isOk())
            .andExpect(jsonPath("$", hasSize(2)))
            .andExpect(jsonPath("$[0].seatKey").value("A1"))
            .andExpect(jsonPath("$[1].status").value("BOOKED"));
    }

    @Test
    @DisplayName("POST /change-status — 403 avec clé PUBLIC")
    void changeStatus_returns403WithPublicKey() throws Exception {
        UUID eventId = UUID.randomUUID();
        ChangeStatusRequest request = new ChangeStatusRequest(List.of("A1"), SeatStatus.CANCELED);

        mockMvc.perform(post("/api/events/{eventId}/change-status", eventId)
                .header("X-Api-Key-Id", KEY_ID)
                .header("X-Api-Key-Secret", KEY_SECRET)
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(request)))
            .andExpect(status().isForbidden());
    }
}
```

---

### Tests de controller — `ChartControllerTest`

```java
@WebMvcTest(ChartController.class)
class ChartControllerTest {

    @Autowired MockMvc mockMvc;
    @Autowired ObjectMapper objectMapper;
    @MockBean ChartService chartService;
    @MockBean ApiKeyService apiKeyService;

    @Test
    @DisplayName("GET /api/charts — 200 liste vide")
    void getCharts_returnsEmptyList() throws Exception {
        when(chartService.findAll()).thenReturn(Collections.emptyList());

        mockMvc.perform(get("/api/charts")
                .header("X-Api-Key-Id", "pk_bo_test")
                .header("X-Api-Key-Secret", "sk_bo_test"))
            .andExpect(status().isOk())
            .andExpect(jsonPath("$").isArray())
            .andExpect(jsonPath("$").isEmpty());
    }

    @Test
    @DisplayName("POST /api/charts — 201 création réussie")
    void createChart_returns201() throws Exception {
        ChartRequest request = new ChartRequest("Salle A", "salle-a");
        ChartResponse response = ChartResponse.builder()
            .id(UUID.randomUUID()).name("Salle A").slug("salle-a")
            .objects(Collections.emptyList()).build();

        when(chartService.create(any())).thenReturn(response);

        mockMvc.perform(post("/api/charts")
                .header("X-Api-Key-Id", "pk_bo_test")
                .header("X-Api-Key-Secret", "sk_bo_test")
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(request)))
            .andExpect(status().isCreated())
            .andExpect(jsonPath("$.slug").value("salle-a"));
    }

    @Test
    @DisplayName("POST /api/charts — 400 si slug manquant")
    void createChart_returns400WhenSlugMissing() throws Exception {
        mockMvc.perform(post("/api/charts")
                .header("X-Api-Key-Id", "pk_bo_test")
                .header("X-Api-Key-Secret", "sk_bo_test")
                .contentType(MediaType.APPLICATION_JSON)
                .content("{"name": "Salle A"}"))
            .andExpect(status().isBadRequest())
            .andExpect(jsonPath("$.errors.slug").exists());
    }

    @Test
    @DisplayName("DELETE /api/charts/{id} — 403 avec clé PUBLIC")
    void deleteChart_returns403WithPublicKey() throws Exception {
        mockMvc.perform(delete("/api/charts/{id}", UUID.randomUUID())
                .header("X-Api-Key-Id", "pk_pub_test")
                .header("X-Api-Key-Secret", "sk_pub_test"))
            .andExpect(status().isForbidden());
    }
}
```

---

### Test d'intégration — `BookingFlowIntegrationTest`

```java
@SpringBootTest(webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
@AutoConfigureMockMvc
@ActiveProfiles("test")
@Transactional
class BookingFlowIntegrationTest {

    @Autowired MockMvc mockMvc;
    @Autowired ObjectMapper objectMapper;
    @Autowired EventRepository eventRepository;
    @Autowired ChartRepository chartRepository;
    @Autowired EventSeatRepository eventSeatRepository;
    @MockBean StringRedisTemplate redisTemplate;
    @MockBean SeatEventPublisher seatEventPublisher;

    private UUID eventId;

    @BeforeEach
    void setUp() {
        ValueOperations<String, String> valueOps = mock(ValueOperations.class);
        when(redisTemplate.opsForValue()).thenReturn(valueOps);
        when(valueOps.setIfAbsent(anyString(), anyString(), any())).thenReturn(true);

        Chart chart = chartRepository.save(Chart.builder()
            .name("Salle Test").slug("salle-test")
            .objectsJson("[{"type":"seat","key":"A1"},{"type":"seat","key":"A2"}]")
            .build());

        Event event = eventRepository.save(Event.builder()
            .title("Concert Test").identifier("concert-test").chart(chart).build());
        eventId = event.getId();

        eventSeatRepository.saveAll(List.of(
            EventSeat.builder().event(event).seatKey("A1").status(SeatStatus.AVAILABLE).build(),
            EventSeat.builder().event(event).seatKey("A2").status(SeatStatus.AVAILABLE).build()
        ));
    }

    @Test
    @DisplayName("Flux complet : hold → book → vérification statut")
    void fullBookingFlow() throws Exception {
        String holdToken = UUID.randomUUID().toString();

        // 1. Hold
        mockMvc.perform(post("/api/events/{id}/hold", eventId)
                .header("X-Api-Key-Id", "pk_pub_test")
                .header("X-Api-Key-Secret", "sk_pub_test")
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(
                    new HoldRequest(List.of("A1"), holdToken))))
            .andExpect(status().isOk());

        // 2. Vérifier statut HOLD
        mockMvc.perform(get("/api/events/{id}/seats", eventId)
                .header("X-Api-Key-Id", "pk_pub_test")
                .header("X-Api-Key-Secret", "sk_pub_test"))
            .andExpect(jsonPath("$[?(@.seatKey=='A1')].status").value("HOLD"))
            .andExpect(jsonPath("$[?(@.seatKey=='A2')].status").value("AVAILABLE"));

        // 3. Book
        mockMvc.perform(post("/api/events/{id}/book", eventId)
                .header("X-Api-Key-Id", "pk_pub_test")
                .header("X-Api-Key-Secret", "sk_pub_test")
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(
                    new BookRequest(List.of("A1"), holdToken))))
            .andExpect(status().isOk());

        // 4. Vérifier statut BOOKED en DB
        EventSeat seat = eventSeatRepository.findByEventIdAndSeatKeyIn(
            eventId, List.of("A1")).get(0);
        assertThat(seat.getStatus()).isEqualTo(SeatStatus.BOOKED);
        assertThat(seat.getHoldToken()).isNull();
    }

    @Test
    @DisplayName("Conflit : deux clients tentent de hold le même siège")
    void concurrentHold_secondFails() throws Exception {
        ValueOperations<String, String> valueOps = mock(ValueOperations.class);
        when(redisTemplate.opsForValue()).thenReturn(valueOps);
        when(valueOps.setIfAbsent(anyString(), anyString(), any()))
            .thenReturn(true)   // premier hold OK
            .thenReturn(false); // deuxième hold KO

        mockMvc.perform(post("/api/events/{id}/hold", eventId)
                .header("X-Api-Key-Id", "pk_pub_test")
                .header("X-Api-Key-Secret", "sk_pub_test")
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(
                    new HoldRequest(List.of("A1"), "token-1"))))
            .andExpect(status().isOk());

        mockMvc.perform(post("/api/events/{id}/hold", eventId)
                .header("X-Api-Key-Id", "pk_pub_test")
                .header("X-Api-Key-Secret", "sk_pub_test")
                .contentType(MediaType.APPLICATION_JSON)
                .content(objectMapper.writeValueAsString(
                    new HoldRequest(List.of("A1"), "token-2"))))
            .andExpect(status().isConflict());
    }
}
```

---

### `application-test.yml`

```yaml
spring:
  datasource:
    url: jdbc:h2:mem:testdb;DB_CLOSE_DELAY=-1;MODE=PostgreSQL
    driver-class-name: org.h2.Driver
    username: sa
    password:

  jpa:
    hibernate:
      ddl-auto: create-drop
    properties:
      hibernate:
        dialect: org.hibernate.dialect.H2Dialect

  security:
    oauth2:
      resourceserver:
        jwt:
          issuer-uri: http://localhost:8180/realms/seating

springdoc:
  api-docs:
    enabled: true
```

---

### Mise à jour de la structure des packages

```
src/main/java/com/seating/
└── config/
    └── OpenApiConfig.java              ← NOUVEAU

src/test/java/com/seating/
├── controller/
│   ├── BookingControllerTest.java      ← NOUVEAU
│   ├── ChartControllerTest.java        ← NOUVEAU
│   ├── EventControllerTest.java        ← NOUVEAU
│   ├── ApiKeyControllerTest.java       ← NOUVEAU
│   ├── AccountControllerTest.java      ← NOUVEAU
│   └── PublicEventControllerTest.java  ← NOUVEAU
├── service/
│   ├── BookingServiceTest.java         ← NOUVEAU
│   ├── ApiKeyServiceTest.java          ← NOUVEAU
│   ├── EventServiceTest.java           ← NOUVEAU
│   ├── ChartServiceTest.java           ← NOUVEAU
│   └── HoldExpirationJobTest.java      ← NOUVEAU
└── integration/
    ├── BookingFlowIntegrationTest.java ← NOUVEAU
    └── SessionFlowIntegrationTest.java ← NOUVEAU

src/test/resources/
└── application-test.yml                ← NOUVEAU
```

---

### Consignes supplémentaires pour l'IA de l'IDE

46. Ajouter `springdoc-openapi-starter-webmvc-ui:2.5.0` dans `pom.xml`.
47. Créer `OpenApiConfig` avec les 4 security schemes : `ApiKey`, `ApiKeySecret`, `BearerAuth`, `SessionToken`.
48. Annoter chaque controller avec `@Tag` et chaque endpoint avec `@Operation` + `@ApiResponses` comme montré dans l'exemple `BookingController`.
49. Autoriser `/swagger-ui.html`, `/swagger-ui/**`, `/v3/api-docs/**` dans `SecurityConfig.permitAll()`.
50. Désactiver Swagger dans `application-prod.yml` avec `springdoc.api-docs.enabled: false`.
51. Les tests de controller utilisent `@WebMvcTest` + `@MockBean` — ne pas charger le contexte Spring complet.
52. Les tests de service utilisent `@ExtendWith(MockitoExtension.class)` + `@InjectMocks` — pas de Spring.
53. Les tests d'intégration utilisent `@SpringBootTest` + `@ActiveProfiles("test")` + H2 en mémoire.
54. Créer `application-test.yml` dans `src/test/resources/` avec H2 en mode PostgreSQL.
55. `BookingFlowIntegrationTest` mock `StringRedisTemplate` et `SeatEventPublisher` pour isoler la logique métier.
56. Chaque test doit avoir un `@DisplayName` en français décrivant le comportement attendu.
57. Les `@ApiResponse` doivent référencer les DTOs de réponse via `@Schema(implementation = ...)` pour que Swagger génère les schémas complets.


---

## Conteneurisation

### Architecture Docker

```
seating-app/
├── backend/
│   └── Dockerfile
├── frontend/
│   └── Dockerfile
└── docker-compose.yml
```

---

### `backend/Dockerfile`

Build multi-stage : Maven build → image JRE slim.

```dockerfile
# ── Stage 1 : build Maven ──────────────────────────────────────────────────
FROM maven:3.9.9-eclipse-temurin-25 AS builder
WORKDIR /app

# Copier pom.xml séparément pour profiter du cache des dépendances
COPY pom.xml .
RUN mvn dependency:go-offline -B

# Copier les sources et builder
COPY src ./src
RUN mvn package -DskipTests -B

# ── Stage 2 : image runtime ────────────────────────────────────────────────
FROM eclipse-temurin:25-jre-alpine
WORKDIR /app

# Utilisateur non-root
RUN addgroup -S seating && adduser -S seating -G seating
USER seating

COPY --from=builder /app/target/placio-backend-*.jar app.jar

# Port exposé
EXPOSE 8080

# Health check interne Docker
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD wget -qO- http://localhost:8080/actuator/health || exit 1

ENTRYPOINT ["java", "-jar", \
  "-Djava.security.egd=file:/dev/./urandom", \
  "-XX:+UseContainerSupport", \
  "-XX:MaxRAMPercentage=75.0", \
  "app.jar"]
```

---

### `frontend/Dockerfile`

Build Vite → Nginx pour servir les assets statiques + reverse proxy vers l'API.

```dockerfile
# ── Stage 1 : build Vite ───────────────────────────────────────────────────
FROM node:22-alpine AS builder
WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build          # dist/
RUN npm run build:widget   # dist-widget/widget.js  (vite.config.widget.js)

# ── Stage 2 : Nginx ────────────────────────────────────────────────────────
FROM nginx:1.25-alpine
RUN rm /etc/nginx/conf.d/default.conf
COPY nginx.conf /etc/nginx/conf.d/app.conf
COPY --from=builder /app/dist /usr/share/nginx/html
COPY --from=builder /app/dist-widget /usr/share/nginx/html/widget

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=3s \
  CMD wget -qO- http://localhost/health || exit 1
```

---

### `frontend/nginx.conf`

```nginx
server {
    listen 80;
    server_name _;

    root /usr/share/nginx/html;
    index index.html;

    # SPA — toutes les routes → index.html
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Widget JS — accessible publiquement
    location /widget/ {
        alias /usr/share/nginx/html/widget/;
        add_header Access-Control-Allow-Origin *;
        add_header Cache-Control "public, max-age=86400";
    }

    # Reverse proxy vers l'API Spring Boot
    location /api/ {
        proxy_pass         http://backend:8080;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
    }

    # Actuator health (pour le healthcheck Docker)
    location /health {
        proxy_pass http://backend:8080/actuator/health;
    }

    # Proxy Keycloak — optionnel, permet d'accéder à KC via le même domaine
    # location /auth/ {
    #     proxy_pass         http://keycloak:8180/;
    #     proxy_set_header   Host              $host;
    #     proxy_set_header   X-Real-IP         $remote_addr;
    #     proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
    #     proxy_set_header   X-Forwarded-Proto $scheme;
    #     proxy_buffer_size  128k;
    #     proxy_buffers      4 256k;
    # }

    # Gzip
    gzip on;
    gzip_types text/plain text/css application/json application/javascript;
    gzip_min_length 1024;
}
```

---

### `docker-compose.yml`

**Ports exposés :**
| Service | Port hôte | Usage |
|---|---|---|
| Frontend Nginx | **80** | Point d'entrée principal (SPA + proxy `/api/`) |
| Backend Spring Boot | **8080** | Dev uniquement — masqué derrière Nginx en prod |
| Keycloak | **8180** | Console d'admin + OIDC endpoints |
| PostgreSQL | **5432** | Dev uniquement |
| Redis | **6379** | Dev uniquement |

```yaml
version: "3.9"

services:

  # ── PostgreSQL (deux bases : seating + keycloak) ───────────────────────────
  postgres:
    image: postgres:16-alpine
    container_name: placio-postgres
    restart: unless-stopped
    environment:
      POSTGRES_DB:       seating
      POSTGRES_USER:     seating
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-seating_dev}
    volumes:
      - postgres_data:/var/lib/postgresql/data
      - ./infra/postgres/init.sql:/docker-entrypoint-initdb.d/init.sql:ro
    ports:
      - "5432:5432"        # dev uniquement
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U seating -d seating"]
      interval: 10s
      timeout: 5s
      retries: 5

  # ── Redis ──────────────────────────────────────────────────────────────────
  redis:
    image: redis:7-alpine
    container_name: placio-redis
    restart: unless-stopped
    command: >
      redis-server
      --requirepass ${REDIS_PASSWORD:-redis_dev}
      --maxmemory 256mb
      --maxmemory-policy allkeys-lru
      --appendonly yes
    volumes:
      - redis_data:/data
    ports:
      - "6379:6379"        # dev uniquement
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "${REDIS_PASSWORD:-redis_dev}", "ping"]
      interval: 10s
      timeout: 3s
      retries: 5

  # ── Keycloak ───────────────────────────────────────────────────────────────
  keycloak:
    image: quay.io/keycloak/keycloak:25.0
    container_name: placio-keycloak
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
    command: start-dev        # remplacer par "start" en prod (TLS requis)
    environment:
      # Admin
      KC_BOOTSTRAP_ADMIN_USERNAME: ${KC_ADMIN_USER:-admin}
      KC_BOOTSTRAP_ADMIN_PASSWORD: ${KC_ADMIN_PASSWORD:-admin_dev}
      # Base de données
      KC_DB:          postgres
      KC_DB_URL:      jdbc:postgresql://postgres:5432/keycloak
      KC_DB_USERNAME: seating
      KC_DB_PASSWORD: ${POSTGRES_PASSWORD:-seating_dev}
      # Réseau
      KC_HOSTNAME:         localhost
      KC_HOSTNAME_PORT:    8180
      KC_HTTP_PORT:        8180
      KC_HEALTH_ENABLED:   "true"
      KC_METRICS_ENABLED:  "true"
    volumes:
      - keycloak_data:/opt/keycloak/data
      - ./infra/keycloak/realm-seating.json:/opt/keycloak/data/import/realm-seating.json:ro
    ports:
      - "8180:8180"
    healthcheck:
      test: ["CMD-SHELL", "curl -sf http://localhost:8180/health/ready || exit 1"]
      interval: 30s
      timeout: 10s
      start_period: 60s
      retries: 5

  # ── Backend Spring Boot ────────────────────────────────────────────────────
  backend:
    build:
      context: ./backend
      dockerfile: Dockerfile
    container_name: placio-backend
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
      keycloak:
        condition: service_healthy
    environment:
      SPRING_DATASOURCE_URL:            jdbc:postgresql://postgres:5432/seating
      SPRING_DATASOURCE_USERNAME:       seating
      SPRING_DATASOURCE_PASSWORD:       ${POSTGRES_PASSWORD:-seating_dev}
      SPRING_DATA_REDIS_HOST:           redis
      SPRING_DATA_REDIS_PORT:           6379
      SPRING_DATA_REDIS_PASSWORD:       ${REDIS_PASSWORD:-redis_dev}
      SEATING_JWT_SECRET:               ${JWT_SECRET:-changeme_base64_256bits_minimum}
      SEATING_HOLD_DURATION_MINUTES:    ${HOLD_DURATION:-10}
      SPRING_JPA_HIBERNATE_DDL_AUTO:    update
      # Keycloak OIDC
      SPRING_SECURITY_OAUTH2_RESOURCESERVER_JWT_ISSUER_URI: http://keycloak:8180/realms/seating
      KEYCLOAK_REALM:                   seating
      KEYCLOAK_CLIENT_ID:               placio-backend
    ports:
      - "8080:8080"        # dev uniquement
    healthcheck:
      test: ["CMD-SHELL", "wget -qO- http://localhost:8080/actuator/health || exit 1"]
      interval: 30s
      timeout: 5s
      start_period: 60s
      retries: 3

  # ── Frontend Vue + Nginx ───────────────────────────────────────────────────
  frontend:
    build:
      context: ./frontend
      dockerfile: Dockerfile
    container_name: placio-frontend
    restart: unless-stopped
    depends_on:
      backend:
        condition: service_healthy
    ports:
      - "80:80"             # ← PORT PRINCIPAL de l'application

volumes:
  postgres_data:
  redis_data:
  keycloak_data:
```

---

### `infra/postgres/init.sql`

Script exécuté automatiquement au premier démarrage de PostgreSQL pour créer la base Keycloak.
La base `seating` est déjà créée via `POSTGRES_DB`.

```sql
-- Créer la base Keycloak avec le même utilisateur
CREATE DATABASE keycloak
    WITH OWNER = seating
    ENCODING = 'UTF8'
    LC_COLLATE = 'en_US.utf8'
    LC_CTYPE = 'en_US.utf8';

GRANT ALL PRIVILEGES ON DATABASE keycloak TO seating;
```

---

### `infra/keycloak/realm-seating.json`

Fichier d'import du realm Keycloak — importé automatiquement au démarrage du conteneur.
Définit le realm `seating` avec les clients et rôles nécessaires.

Contenu minimal (à personnaliser) :
```json
{
  "realm": "seating",
  "enabled": true,
  "displayName": "Seating App",
  "registrationAllowed": false,
  "loginWithEmailAllowed": true,
  "clients": [
    {
      "clientId": "placio-backend",
      "enabled": true,
      "bearerOnly": true,
      "publicClient": false
    },
    {
      "clientId": "placio-frontend",
      "enabled": true,
      "publicClient": true,
      "redirectUris": ["http://localhost/*", "https://votre-domaine.com/*"],
      "webOrigins": ["http://localhost", "https://votre-domaine.com"],
      "standardFlowEnabled": true,
      "directAccessGrantsEnabled": false
    }
  ],
  "roles": {
    "realm": [
      { "name": "ROLE_BACKOFFICE", "description": "Accès éditeur et gestion" },
      { "name": "ROLE_USER",       "description": "Utilisateur standard" }
    ]
  },
  "defaultRoles": ["ROLE_USER"]
}
```

---

### `.env` (fichier à créer à la racine, ne jamais committer)

```env
# PostgreSQL
POSTGRES_PASSWORD=un_mot_de_passe_fort

# Redis
REDIS_PASSWORD=un_autre_mot_de_passe

# JWT session tokens (seating app interne)
# Générer avec : openssl rand -base64 32
JWT_SECRET=VOTRE_CLE_BASE64_256_BITS

# Hold TTL (minutes)
HOLD_DURATION=10

# Keycloak admin
KC_ADMIN_USER=admin
KC_ADMIN_PASSWORD=un_mot_de_passe_admin_fort
```

Ajouter `.env` dans `.gitignore` :
```
.env
*.env.local
```

---

### `.dockerignore` (backend)

```
target/
*.md
.git/
.idea/
*.iml
```

### `.dockerignore` (frontend)

```
node_modules/
dist/
dist-widget/
.git/
*.md
```

---

### Commandes utiles

```bash
# Démarrer toute la stack
docker compose up -d

# Rebuild après modification du code
docker compose up -d --build backend
docker compose up -d --build frontend

# Voir les logs en temps réel
docker compose logs -f backend
docker compose logs -f redis

# Accéder à PostgreSQL
docker compose exec postgres psql -U seating -d seating

# Accéder à Redis CLI
docker compose exec redis redis-cli -a $REDIS_PASSWORD

# Arrêter sans supprimer les volumes
docker compose stop

# Tout supprimer (volumes inclus) — ATTENTION : perte des données
docker compose down -v
```

---

### Profil `prod` — `application-prod.yml`

En production, surcharger `application.yml` avec ce profil activé via
`SPRING_PROFILES_ACTIVE=prod` dans les variables d'environnement Docker :

```yaml
spring:
  jpa:
    hibernate:
      ddl-auto: validate      # ne jamais modifier le schéma automatiquement en prod
    show-sql: false

logging:
  level:
    com.placio: INFO
    org.hibernate: WARN

server:
  tomcat:
    max-threads: 200
    min-spare-threads: 20
```

Ajouter dans `docker-compose.yml` sous `backend.environment` pour activer le profil prod :
```yaml
SPRING_PROFILES_ACTIVE: prod
```

---

### Mise à jour de la structure des packages

```
src/main/resources/
├── application.yml
└── application-prod.yml     ← NOUVEAU
```

---

### Consignes supplémentaires pour l'IA de l'IDE

23. Créer `application-prod.yml` dans `src/main/resources/` avec `ddl-auto: validate` et `show-sql: false`.
24. Le `Dockerfile` backend doit utiliser un build multi-stage Maven → JRE Alpine avec utilisateur non-root.
25. Les variables d'environnement Docker (`SPRING_DATASOURCE_URL`, etc.) doivent surcharger automatiquement `application.yml` grâce au mécanisme standard Spring Boot (pas de `@Value` supplémentaire nécessaire).
26. Le `healthcheck` du backend pointe sur `/actuator/health` — s'assurer que `management.endpoints.web.exposure.include: health` est bien dans `application.yml`.
27. Utiliser **Java 25** (pas 21) — les Dockerfiles utilisent `eclipse-temurin:25-jre-alpine` et `maven:3.9.9-eclipse-temurin-25`.
28. Ajouter `spring-boot-starter-oauth2-resource-server` dans `pom.xml`.
29. `SecurityConfig` doit supporter les **deux mécanismes d'auth** : JWT Bearer (Keycloak) ET API Key header, en cascade dans la chaîne de filtres.
30. Le bean `JwtAuthenticationConverter` extrait les rôles depuis `realm_access.roles` (structure imbriquée Keycloak), PAS depuis `scope` ou `authorities`.
31. `AccountController` injecte `@AuthenticationPrincipal Jwt jwt` — utiliser `jwt.getSubject()` pour le `keycloakId`.
32. `UserSyncService.syncUser()` est appelé à chaque requête sur `/api/account/**` (pas de cache — on veut `lastLoginAt` à jour).
33. L'entité `ApiKey` ajoute le champ `createdByKeycloakId` (nullable) pour lier les clés à un utilisateur.
34. Le fichier `infra/keycloak/realm-seating.json` doit être monté en volume read-only dans le conteneur Keycloak — il est importé automatiquement au premier démarrage via le volume `/opt/keycloak/data/import/`.
35. Le fichier `infra/postgres/init.sql` crée la base `keycloak` au premier démarrage PostgreSQL (la base `seating` est créée par `POSTGRES_DB`).
36. Ajouter `@EnableMethodSecurity` sur `SecurityConfig` pour activer `@PreAuthorize` sur `AccountController`.
