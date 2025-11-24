# Scaling Multi-Tenant Applications on Coolify

Complete guide for handling high load in multi-tenant SaaS applications with 100s of sites and millions of users.

---

## 📋 **Table of Contents**

1. [Architecture Overview](#architecture-overview)
2. [Approach A: Shared Application](#approach-a-shared-application-recommended)
3. [Approach B: Isolated Applications](#approach-b-isolated-applications-per-tenant)
4. [Database Scaling](#database-scaling)
5. [Caching Strategy](#caching-strategy)
6. [Load Balancing](#load-balancing)
7. [Performance Optimization](#performance-optimization)
8. [Monitoring & Alerting](#monitoring--alerting)
9. [Cost Optimization](#cost-optimization)

---

## Architecture Overview

### **Your Use Case**
- **Course Platform**: 100+ tenant sites on `*.course-app.edesy.in`
- **Blog Platform**: 100+ tenant sites on `*.blog-app.edesy.in`
- Each tenant: 100-10,000+ users
- **Total Load**: Potentially millions of requests/day

### **Scaling Challenges**
1. 🔥 **High Traffic**: Many concurrent users across all tenants
2. 💾 **Database Load**: Shared database with millions of rows
3. 🌐 **SSL Certificates**: 200+ domains to manage
4. 📦 **Container Resources**: CPU/Memory limits per tenant
5. 💰 **Cost**: Infrastructure costs vs revenue

---

## Approach A: Shared Application (Recommended)

### **✅ When to Use**
- 100+ tenants
- Similar resource needs per tenant
- Cost-efficiency is important
- Easier management/updates

### **Architecture Diagram**

```
Internet
   │
   ▼
┌──────────────────────────────────────┐
│   Cloudflare (CDN + DDoS)            │ ← Static assets, DDoS protection
└──────────────┬───────────────────────┘
               │
               ▼
┌──────────────────────────────────────┐
│   Traefik (Load Balancer + SSL)     │ ← Wildcard SSL, routing
└──────────────┬───────────────────────┘
               │
       ┌───────┴────────┐
       ▼                ▼
┌─────────────┐   ┌─────────────┐
│   App 1     │   │   App 2     │   ← Horizontal scaling
│ (Course)    │   │ (Course)    │
└──────┬──────┘   └──────┬──────┘
       │                 │
       └────────┬────────┘
                ▼
    ┌───────────────────────┐
    │   PostgreSQL          │
    │   (Primary)           │
    └──────────┬────────────┘
               │
       ┌───────┴────────┐
       ▼                ▼
┌─────────────┐   ┌─────────────┐
│  Read       │   │  Read       │   ← Read replicas for queries
│  Replica 1  │   │  Replica 2  │
└─────────────┘   └─────────────┘

       ┌────────────┐
       │   Redis    │ ← Cache + Sessions + Queue
       └────────────┘
```

### **Implementation Steps**

#### **1. Set Up Tenant Identification**

```php
// app/Http/Middleware/IdentifyTenant.php
<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Tenant;
use Illuminate\Http\Request;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next)
    {
        // Extract tenant from subdomain or custom domain
        $host = $request->getHost();

        // Check if custom domain
        $tenant = Tenant::where('custom_domain', $host)->first();

        // Otherwise extract from subdomain
        if (!$tenant) {
            // site1.course-app.edesy.in -> site1
            $subdomain = explode('.', $host)[0];
            $tenant = Tenant::where('subdomain', $subdomain)->first();
        }

        if (!$tenant) {
            abort(404, 'Tenant not found');
        }

        // Set tenant globally for request
        app()->instance('tenant', $tenant);

        // Set tenant connection for database queries
        config(['database.connections.tenant.database' => "tenant_{$tenant->id}"]);

        return $next($request);
    }
}
```

#### **2. Database Tenant Isolation**

**Option 2a: Single Database with tenant_id (Simple)**

```php
// All models include tenant_id
class Course extends Model
{
    protected $fillable = ['tenant_id', 'title', 'description'];

    // Global scope to automatically filter by tenant
    protected static function booted()
    {
        static::addGlobalScope('tenant', function ($query) {
            if (app()->has('tenant')) {
                $query->where('tenant_id', app('tenant')->id);
            }
        });

        static::creating(function ($model) {
            if (app()->has('tenant')) {
                $model->tenant_id = app('tenant')->id;
            }
        });
    }
}

// Usage - automatically scoped to current tenant
$courses = Course::all(); // Only returns current tenant's courses
```

**Option 2b: Separate Database Per Tenant (Better Isolation)**

```php
// config/database.php
'connections' => [
    'tenant' => [
        'driver' => 'pgsql',
        'host' => env('DB_HOST'),
        'database' => null, // Set dynamically
        'username' => env('DB_USERNAME'),
        'password' => env('DB_PASSWORD'),
    ],
],

// Switch database connection per request
DB::connection('tenant')->table('courses')->get();
```

#### **3. Horizontal Scaling with Docker Compose**

```yaml
# docker-compose.yml
version: '3.8'

services:
  app:
    image: your-course-app:latest
    deploy:
      replicas: 3  # Scale to 3 instances
      resources:
        limits:
          cpus: '1.0'
          memory: 1G
        reservations:
          cpus: '0.5'
          memory: 512M
    environment:
      - DB_CONNECTION=pgsql
      - REDIS_HOST=redis
      - QUEUE_CONNECTION=redis
    networks:
      - app-network

  postgres:
    image: postgres:15
    volumes:
      - postgres-data:/var/lib/postgresql/data
    environment:
      - POSTGRES_MAX_CONNECTIONS=200
      - SHARED_BUFFERS=256MB
    networks:
      - app-network

  postgres-replica:
    image: postgres:15
    environment:
      - POSTGRES_REPLICATION_MODE=slave
      - POSTGRES_MASTER_HOST=postgres
    networks:
      - app-network

  redis:
    image: redis:7-alpine
    command: redis-server --maxmemory 512mb --maxmemory-policy allkeys-lru
    networks:
      - app-network

  traefik:
    image: traefik:v2.10
    command:
      - "--providers.docker=true"
      - "--entrypoints.web.address=:80"
      - "--entrypoints.websecure.address=:443"
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    networks:
      - app-network

volumes:
  postgres-data:

networks:
  app-network:
    driver: bridge
```

#### **4. Scale via Coolify API**

```php
// Scale application to 5 replicas
use Illuminate\Support\Facades\Http;

$response = Http::withToken(config('services.coolify.api_token'))
    ->patch("https://coolify.example.com/api/v1/applications/{$appUuid}", [
        'replicas' => 5, // Scale to 5 instances
    ]);
```

---

## Approach B: Isolated Applications Per Tenant

### **✅ When to Use**
- < 50 tenants
- Enterprise customers (need strong isolation)
- Variable resource needs per tenant
- Custom configurations per tenant

### **Architecture**

```
Traefik (Load Balancer)
   │
   ├─► Tenant 1 App (site1.course-app.edesy.in)
   │   └─► Dedicated DB (tenant_1)
   │
   ├─► Tenant 2 App (site2.course-app.edesy.in)
   │   └─► Dedicated DB (tenant_2)
   │
   └─► Tenant 3 App (learncooking.com)
       └─► Dedicated DB (tenant_3)
```

### **Implementation**

```php
// Service to provision new tenant
class TenantProvisioningService
{
    protected CoolifyDomainManager $coolify;

    public function provisionTenant(Tenant $tenant): void
    {
        // 1. Create new application via Coolify API
        $response = Http::withToken(config('services.coolify.api_token'))
            ->post('https://coolify.example.com/api/v1/applications', [
                'name' => "course-{$tenant->subdomain}",
                'project_uuid' => config('services.coolify.project_uuid'),
                'environment_name' => 'production',
                'server_uuid' => $this->selectLeastLoadedServer(),
                'git_repository' => 'https://github.com/your-org/course-app',
                'git_branch' => 'main',
                'build_pack' => 'dockerfile',
            ]);

        $applicationUuid = $response->json('uuid');

        // 2. Add domains
        $domains = ["{$tenant->subdomain}.course-app.edesy.in"];
        if ($tenant->custom_domain) {
            $domains[] = $tenant->custom_domain;
        }

        $this->coolify->addDomains($applicationUuid, $domains);

        // 3. Set environment variables
        Http::withToken(config('services.coolify.api_token'))
            ->post("https://coolify.example.com/api/v1/applications/{$applicationUuid}/envs", [
                'key' => 'TENANT_ID',
                'value' => $tenant->id,
            ]);

        // 4. Deploy application
        Http::withToken(config('services.coolify.api_token'))
            ->post("https://coolify.example.com/api/v1/applications/{$applicationUuid}/start");

        // 5. Save application UUID
        $tenant->update([
            'coolify_app_uuid' => $applicationUuid,
            'status' => 'provisioning',
        ]);
    }

    protected function selectLeastLoadedServer(): string
    {
        // Get all servers
        $response = Http::withToken(config('services.coolify.api_token'))
            ->get('https://coolify.example.com/api/v1/servers');

        $servers = $response->json();

        // Select server with least resources used
        return collect($servers)
            ->sortBy('resource_usage')
            ->first()['uuid'];
    }
}
```

---

## Database Scaling

### **1. Read Replicas**

```php
// config/database.php
'connections' => [
    'pgsql' => [
        'read' => [
            'host' => [
                '10.0.1.2', // Replica 1
                '10.0.1.3', // Replica 2
            ],
        ],
        'write' => [
            'host' => ['10.0.1.1'], // Primary
        ],
        'driver' => 'pgsql',
        // ...
    ],
],

// Usage - automatic read/write splitting
Course::all(); // Uses read replica
Course::create([...]); // Uses primary
```

### **2. Connection Pooling**

```bash
# Install PgBouncer
docker run -d \
  --name pgbouncer \
  -p 6432:6432 \
  -e POSTGRESQL_HOST=postgres \
  -e POSTGRESQL_PORT=5432 \
  -e PGBOUNCER_POOL_MODE=transaction \
  -e PGBOUNCER_MAX_CLIENT_CONN=1000 \
  -e PGBOUNCER_DEFAULT_POOL_SIZE=50 \
  bitnami/pgbouncer:latest
```

```php
// .env
DB_HOST=pgbouncer
DB_PORT=6432
```

### **3. Database Partitioning**

```sql
-- Partition courses table by tenant_id
CREATE TABLE courses (
    id BIGSERIAL,
    tenant_id INTEGER NOT NULL,
    title VARCHAR(255),
    created_at TIMESTAMP
) PARTITION BY HASH (tenant_id);

-- Create partitions (100 partitions for load distribution)
CREATE TABLE courses_0 PARTITION OF courses
    FOR VALUES WITH (MODULUS 100, REMAINDER 0);

CREATE TABLE courses_1 PARTITION OF courses
    FOR VALUES WITH (MODULUS 100, REMAINDER 1);

-- ... create 98 more partitions
```

### **4. Database Indexes**

```php
// Create indexes for common queries
Schema::table('courses', function (Blueprint $table) {
    $table->index(['tenant_id', 'created_at']);
    $table->index(['tenant_id', 'status']);
    $table->index('slug');
});

// Use covering indexes for common queries
Schema::table('enrollments', function (Blueprint $table) {
    $table->index(['tenant_id', 'user_id', 'course_id', 'status']);
});
```

---

## Caching Strategy

### **1. Application-Level Cache**

```php
// Cache tenant data
$tenant = Cache::remember("tenant:{$subdomain}", 3600, function () use ($subdomain) {
    return Tenant::where('subdomain', $subdomain)->first();
});

// Cache course listings
$courses = Cache::tags(['tenant:' . $tenant->id, 'courses'])
    ->remember("tenant:{$tenant->id}:courses", 600, function () {
        return Course::with('instructor')->paginate(20);
    });

// Invalidate cache on updates
class Course extends Model
{
    protected static function booted()
    {
        static::saved(function ($course) {
            Cache::tags(['tenant:' . $course->tenant_id, 'courses'])->flush();
        });
    }
}
```

### **2. Redis Configuration**

```php
// config/cache.php
'redis' => [
    'client' => 'predis',
    'options' => [
        'cluster' => 'redis',
        'prefix' => env('REDIS_PREFIX', 'course_app_'),
    ],
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => 0,
        'read_write_timeout' => 60,
    ],
    'cache' => [
        'database' => 1,
    ],
    'queue' => [
        'database' => 2,
    ],
],
```

### **3. Full-Page Caching**

```php
// routes/web.php
Route::middleware(['cache.headers:public;max_age=3600'])->group(function () {
    Route::get('/courses', [CourseController::class, 'index']);
    Route::get('/courses/{slug}', [CourseController::class, 'show']);
});
```

### **4. CDN for Static Assets**

```php
// config/filesystems.php
'cloudflare' => [
    'driver' => 's3',
    'key' => env('CLOUDFLARE_R2_ACCESS_KEY'),
    'secret' => env('CLOUDFLARE_R2_SECRET_KEY'),
    'region' => 'auto',
    'bucket' => env('CLOUDFLARE_R2_BUCKET'),
    'url' => env('CLOUDFLARE_R2_URL'),
    'endpoint' => env('CLOUDFLARE_R2_ENDPOINT'),
],

// Serve images from CDN
<img src="{{ Storage::disk('cloudflare')->url('courses/thumbnails/' . $course->thumbnail) }}">
```

---

## Load Balancing

### **1. Traefik Configuration**

```yaml
# traefik-dynamic.yml
http:
  routers:
    course-app:
      rule: "HostRegexp(`{subdomain:[a-z0-9-]+}.course-app.edesy.in`)"
      service: course-app-service
      middlewares:
        - rate-limit
        - compress
      tls:
        certResolver: letsencrypt-dns

  services:
    course-app-service:
      loadBalancer:
        sticky:
          cookie:
            name: course_app_sticky
            secure: true
            httpOnly: true
        healthCheck:
          path: /health
          interval: 10s
          timeout: 3s
        servers:
          - url: "http://app-1:8000"
          - url: "http://app-2:8000"
          - url: "http://app-3:8000"

  middlewares:
    rate-limit:
      rateLimit:
        average: 100
        period: 1s
        burst: 50

    compress:
      compress: {}
```

### **2. Health Check Endpoint**

```php
// routes/api.php
Route::get('/health', function () {
    try {
        // Check database
        DB::connection()->getPdo();

        // Check Redis
        Redis::ping();

        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'error' => $e->getMessage(),
        ], 503);
    }
});
```

---

## Performance Optimization

### **1. Database Query Optimization**

```php
// ❌ Bad: N+1 Query Problem
$courses = Course::all();
foreach ($courses as $course) {
    echo $course->instructor->name; // Separate query per course
}

// ✅ Good: Eager Loading
$courses = Course::with('instructor', 'enrollments')->get();
foreach ($courses as $course) {
    echo $course->instructor->name; // Already loaded
}

// ✅ Better: Lazy Eager Loading
$courses = Course::all();
$courses->load('instructor', 'enrollments');

// ✅ Best: Select only needed columns
$courses = Course::select('id', 'title', 'instructor_id')
    ->with('instructor:id,name')
    ->get();
```

### **2. Queue Long-Running Tasks**

```php
// ❌ Bad: Process video encoding in request
public function uploadVideo(Request $request)
{
    $video = $request->file('video');
    $encoded = $this->encodeVideo($video); // Takes 2 minutes!
    return response()->json(['video_id' => $encoded->id]);
}

// ✅ Good: Queue the encoding
public function uploadVideo(Request $request)
{
    $video = $request->file('video');
    $path = $video->store('videos/pending');

    EncodeVideoJob::dispatch($path, auth()->user());

    return response()->json(['status' => 'processing']);
}

// app/Jobs/EncodeVideoJob.php
class EncodeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        // Encode video in background
        $encoded = FFMpeg::encode($this->path);

        // Notify user when done
        $this->user->notify(new VideoEncodingComplete($encoded));
    }
}
```

### **3. Implement Pagination**

```php
// ❌ Bad: Load all courses
$courses = Course::all(); // 10,000 courses loaded into memory

// ✅ Good: Paginate
$courses = Course::paginate(20); // Only 20 courses per page

// ✅ Better: Cursor pagination for large datasets
$courses = Course::cursorPaginate(20); // More efficient for large offsets
```

### **4. Use Chunk for Large Datasets**

```php
// Process 100,000 enrollments without memory issues
Enrollment::where('status', 'pending')
    ->chunk(1000, function ($enrollments) {
        foreach ($enrollments as $enrollment) {
            $enrollment->processPayment();
        }
    });
```

---

## Monitoring & Alerting

### **1. Application Monitoring**

```php
// Install Laravel Telescope for development
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate

// Install Sentry for production errors
composer require sentry/sentry-laravel
```

```php
// config/logging.php
'channels' => [
    'sentry' => [
        'driver' => 'sentry',
        'level' => 'error',
        'bubble' => true,
    ],
],
```

### **2. Performance Metrics**

```php
// Track slow queries
DB::listen(function ($query) {
    if ($query->time > 1000) { // > 1 second
        Log::warning('Slow query detected', [
            'sql' => $query->sql,
            'time' => $query->time,
            'bindings' => $query->bindings,
        ]);
    }
});
```

### **3. Resource Monitoring**

```bash
# Monitor Docker containers
docker stats

# Install Prometheus + Grafana
docker-compose up -d prometheus grafana

# View metrics at http://localhost:3000
```

### **4. Custom Metrics**

```php
// Track tenant-specific metrics
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

$registry = app(CollectorRegistry::class);

// Counter: Total requests per tenant
$counter = $registry->getOrRegisterCounter(
    'app',
    'requests_total',
    'Total requests per tenant',
    ['tenant_id']
);
$counter->inc(['tenant_id' => $tenant->id]);

// Gauge: Active users per tenant
$gauge = $registry->getOrRegisterGauge(
    'app',
    'active_users',
    'Active users per tenant',
    ['tenant_id']
);
$gauge->set(User::where('tenant_id', $tenant->id)->active()->count(), ['tenant_id' => $tenant->id]);
```

---

## Cost Optimization

### **1. Server Sizing**

```
Small Tenants (< 100 users): Share 1 server
├─ 4 CPU cores
├─ 8 GB RAM
└─ 50 GB SSD

Medium Tenants (100-1000 users): 2-3 servers
├─ 8 CPU cores per server
├─ 16 GB RAM per server
└─ 100 GB SSD per server

Large Tenants (1000+ users): Dedicated cluster
├─ 4+ servers
├─ 16 CPU cores per server
├─ 32 GB RAM per server
└─ 200 GB SSD per server
```

### **2. Auto-Scaling**

```yaml
# docker-compose.yml with auto-scaling
services:
  app:
    image: course-app:latest
    deploy:
      replicas: 3
      update_config:
        parallelism: 1
        delay: 10s
      restart_policy:
        condition: on-failure
      resources:
        limits:
          cpus: '1.0'
          memory: 1G
        reservations:
          cpus: '0.5'
          memory: 512M
```

### **3. Cost Monitoring**

```php
// Track resource usage per tenant
class TenantMetrics extends Model
{
    protected $fillable = [
        'tenant_id',
        'date',
        'requests_count',
        'bandwidth_mb',
        'storage_mb',
        'compute_minutes',
    ];

    public function calculateCost(): float
    {
        return (
            ($this->requests_count / 1000000) * 0.01 + // $0.01 per million requests
            ($this->bandwidth_mb / 1000) * 0.05 + // $0.05 per GB bandwidth
            ($this->storage_mb / 1000) * 0.10 + // $0.10 per GB storage/month
            ($this->compute_minutes / 60) * 0.02 // $0.02 per compute hour
        );
    }
}

// Usage
$monthlyCost = TenantMetrics::where('tenant_id', $tenant->id)
    ->whereMonth('date', now()->month)
    ->get()
    ->sum(fn($metric) => $metric->calculateCost());
```

---

## 🎯 **Recommended Setup for 100+ Tenants**

### **Infrastructure**

```
3 Application Servers (8 cores, 16GB RAM each)
├─ 3 replicas per server = 9 total app instances
└─ Load balanced via Traefik

1 Database Server (16 cores, 32GB RAM, 500GB SSD)
├─ PostgreSQL 15 with PgBouncer
└─ 2 Read Replicas (8 cores, 16GB RAM each)

1 Redis Server (4 cores, 8GB RAM)
├─ Cache + Sessions + Queues
└─ Redis Cluster for high availability

CDN (Cloudflare)
├─ Static assets
├─ DDoS protection
└─ Edge caching
```

### **Estimated Capacity**

- **Concurrent Users**: 10,000+
- **Requests/Second**: 1,000+
- **Monthly Cost**: $500-1000 (depending on cloud provider)

---

## 🚀 **Quick Start Checklist**

- [ ] Implement tenant identification middleware
- [ ] Set up database tenant isolation (tenant_id or separate DBs)
- [ ] Configure horizontal scaling (3+ app replicas)
- [ ] Set up Redis for caching
- [ ] Configure database read replicas
- [ ] Implement query optimization (eager loading, indexes)
- [ ] Queue long-running tasks
- [ ] Set up health checks
- [ ] Configure Traefik load balancing
- [ ] Enable application monitoring (Sentry, Telescope)
- [ ] Implement CDN for static assets
- [ ] Set up auto-scaling rules
- [ ] Configure alerting (Slack, email)
- [ ] Load test with realistic traffic

---

## 📚 **Additional Resources**

- [Laravel Multi-Tenancy Package](https://github.com/spatie/laravel-multitenancy)
- [PostgreSQL Partitioning Docs](https://www.postgresql.org/docs/current/ddl-partitioning.html)
- [Traefik Load Balancing](https://doc.traefik.io/traefik/routing/services/)
- [Redis Best Practices](https://redis.io/docs/management/optimization/)

---

**Ready to scale!** 🎉📈
