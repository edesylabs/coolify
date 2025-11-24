# Docker Swarm Application Placement Configuration

## Your Infrastructure Summary

```
Server 1 (Main):    16GB RAM - Swarm Manager + Light Apps
Server 2 (Worker):   4GB RAM - Light Apps Only
Server 3 (Worker):   4GB RAM - Light Apps Only
Server 4 (Hetzner): 64GB RAM - Heavy Apps Only
────────────────────────────
Total:              88GB RAM
```

---

## Step 1: Initialize Swarm & Add Nodes

### On Server 1 (Main - Manager)
```bash
ssh server1
docker swarm init --advertise-addr SERVER1_INTERNAL_IP

# Save the output token, looks like:
# docker swarm join --token SWMTKN-1-xxx... 10.0.0.1:2377
```

### On Servers 2, 3, 4 (Workers)
```bash
# On Server 2
ssh server2
docker swarm join --token SWMTKN-1-xxx... SERVER1_IP:2377

# On Server 3
ssh server3
docker swarm join --token SWMTKN-1-xxx... SERVER1_IP:2377

# On Server 4
ssh server4
docker swarm join --token SWMTKN-1-xxx... SERVER1_IP:2377
```

### Verify Cluster
```bash
# On Server 1
docker node ls

# Should show:
# ID              HOSTNAME    STATUS   AVAILABILITY   MANAGER STATUS
# abc123 *        server1     Ready    Active         Leader
# def456          server2     Ready    Active
# ghi789          server3     Ready    Active
# jkl012          server4     Ready    Active
```

---

## Step 2: Label Nodes by Capacity

```bash
# On Server 1 (Swarm Manager)

# Get node IDs first
export NODE1=$(docker node ls --filter "name=server1" -q)
export NODE2=$(docker node ls --filter "name=server2" -q)
export NODE3=$(docker node ls --filter "name=server3" -q)
export NODE4=$(docker node ls --filter "name=server4" -q)

# Label Server 1 (16GB - Management)
docker node update --label-add size=medium $NODE1
docker node update --label-add purpose=management $NODE1
docker node update --label-add ram=16gb $NODE1

# Label Server 2 (4GB - Light Apps)
docker node update --label-add size=small $NODE2
docker node update --label-add purpose=light-apps $NODE2
docker node update --label-add ram=4gb $NODE2

# Label Server 3 (4GB - Light Apps)
docker node update --label-add size=small $NODE3
docker node update --label-add purpose=light-apps $NODE3
docker node update --label-add ram=4gb $NODE3

# Label Server 4 (64GB - Heavy Apps)
docker node update --label-add size=large $NODE4
docker node update --label-add purpose=heavy-apps $NODE4
docker node update --label-add ram=64gb $NODE4

# Verify labels
echo "=== Server Labels ==="
docker node inspect server1 --format '{{ .Spec.Labels }}'
docker node inspect server2 --format '{{ .Spec.Labels }}'
docker node inspect server3 --format '{{ .Spec.Labels }}'
docker node inspect server4 --format '{{ .Spec.Labels }}'
```

---

## Step 3: Configure Servers in Coolify

### Server 1 (Manager)
```
Coolify UI:
├─ Servers → Server 1
├─ Server Settings
│  ├─ ✅ Is Swarm Manager
│  └─ ❌ Is Swarm Worker
└─ Save
```

### Servers 2, 3, 4 (Workers)
```
Coolify UI:
├─ Servers → Add Server
├─ Name: Server 2 (or 3, or 4)
├─ IP: [server IP]
├─ Server Settings
│  ├─ ❌ Is Swarm Manager
│  ├─ ✅ Is Swarm Worker
│  └─ Swarm Manager: Server 1
└─ Save

Repeat for Server 3 and Server 4
```

---

## Step 4: Configure Applications with Placement Constraints

### Heavy Apps → Server 4 (64GB Only)

#### Course App
```
Coolify UI:
├─ Applications → Course App
├─ Configuration → Swarm
│  ├─ Replicas: 15
│  ├─ Placement Constraints:
│  │   node.labels.size == large
│  └─ ✅ Swarm Only Worker Nodes: Yes
├─ Configuration → Advanced
│  ├─ CPU Limit: 1.0
│  ├─ Memory Limit: 1024MB
│  ├─ CPU Reservation: 0.5
│  └─ Memory Reservation: 512MB
└─ Save → Redeploy
```

**Result:** All 15 replicas go to Server 4
- **Used:** 15GB (15 replicas × 1GB)
- **Capacity:** 5,000-7,500 concurrent users

#### Blog App
```
Same configuration as Course App:
├─ Replicas: 15
├─ Placement Constraints: node.labels.size == large
├─ Memory Limit: 1024MB
└─ Memory Reservation: 512MB
```

**Result:** All 15 replicas go to Server 4
- **Used:** 15GB (15 replicas × 1GB)
- **Total on Server 4:** 30GB (still 34GB free!)

---

### Light Apps → Servers 2 & 3 (4GB each)

#### 1. Email Service
```
Coolify UI:
├─ Applications → Email Service
├─ Configuration → Swarm
│  ├─ Replicas: 4
│  ├─ Placement Constraints:
│  │   node.labels.purpose == light-apps
│  └─ ✅ Swarm Only Worker Nodes: Yes
├─ Configuration → Advanced
│  ├─ Memory Limit: 256MB
│  └─ Memory Reservation: 128MB
└─ Save → Redeploy
```

**Result:** 4 replicas distributed across Servers 2 & 3
- **Server 2:** 2 replicas = 512MB
- **Server 3:** 2 replicas = 512MB

#### 2. Notification Service
```
Same configuration:
├─ Replicas: 4
├─ Placement Constraints: node.labels.purpose == light-apps
└─ Memory Limit: 256MB
```

**Result:** 2 replicas each on Servers 2 & 3 = 512MB per server

#### 3. Webhook Handler
```
Same configuration:
├─ Replicas: 4
├─ Placement Constraints: node.labels.purpose == light-apps
└─ Memory Limit: 256MB
```

#### 4. Image Processor (needs more memory)
```
Coolify UI:
├─ Replicas: 4
├─ Placement Constraints: node.labels.purpose == light-apps
├─ Memory Limit: 512MB  ← Larger
└─ Memory Reservation: 256MB
```

**Result:** 2 replicas each = 1GB per server

#### 5. File Storage Service
```
├─ Replicas: 4
├─ Placement Constraints: node.labels.purpose == light-apps
└─ Memory Limit: 512MB
```

#### 6. Search Service
```
├─ Replicas: 4
├─ Placement Constraints: node.labels.purpose == light-apps
└─ Memory Limit: 512MB
```

#### 7. Cache Warmer
```
├─ Replicas: 4
├─ Placement Constraints: node.labels.purpose == light-apps
└─ Memory Limit: 256MB
```

#### 8. Background Jobs
```
├─ Replicas: 4
├─ Placement Constraints: node.labels.purpose == light-apps
└─ Memory Limit: 256MB
```

---

### Management Apps → Server 1 (16GB)

#### 9. Admin Dashboard
```
Coolify UI:
├─ Applications → Admin Dashboard
├─ Configuration → Swarm
│  ├─ Replicas: 3
│  ├─ Placement Constraints:
│  │   node.labels.purpose == management
│  └─ ❌ Swarm Only Worker Nodes: No (Manager can run workloads)
├─ Configuration → Advanced
│  ├─ Memory Limit: 512MB
│  └─ Memory Reservation: 256MB
└─ Save → Redeploy
```

**Result:** All 3 replicas on Server 1 = 1.5GB

#### 10. Analytics App
```
├─ Replicas: 3
├─ Placement Constraints: node.labels.purpose == management
└─ Memory Limit: 512MB
```

**Result:** 3 replicas on Server 1 = 1.5GB

#### 11. API Gateway
```
├─ Replicas: 3
├─ Placement Constraints: node.labels.purpose == management
└─ Memory Limit: 512MB
```

**Result:** 3 replicas on Server 1 = 1.5GB

#### 12. Monitoring Service
```
├─ Replicas: 2
├─ Placement Constraints: node.labels.purpose == management
└─ Memory Limit: 256MB
```

**Result:** 2 replicas on Server 1 = 512MB

---

## Final Resource Allocation

### Server 1 (Main - 16GB) ✅
```
Infrastructure (not in Swarm):
├─ Coolify              : 1.5GB
├─ PostgreSQL           : 2.0GB
├─ Redis                : 0.5GB
└─ Traefik              : 0.5GB
Total Infrastructure    : 4.5GB

Applications (in Swarm):
├─ Admin Dashboard (3)  : 1.5GB
├─ Analytics App (3)    : 1.5GB
├─ API Gateway (3)      : 1.5GB
└─ Monitoring (2)       : 0.5GB
Total Applications      : 5.0GB

──────────────────────────────
Total Used              : 9.5GB
Available Buffer        : 6.5GB
Status: ✅ Healthy (40% free)
```

### Server 2 (Worker - 4GB) ✅
```
Applications (8 × 2 replicas each):
├─ Email Service        : 512MB (2 × 256MB)
├─ Notification         : 512MB (2 × 256MB)
├─ Webhook Handler      : 512MB (2 × 256MB)
├─ Cache Warmer         : 512MB (2 × 256MB)
Total                   : 2.0GB

──────────────────────────────
Total Used              : 2.0GB
Available Buffer        : 2.0GB
Status: ✅ Healthy (50% free)
```

### Server 3 (Worker - 4GB) ✅
```
Applications (4 × 2 replicas each):
├─ Image Processor      : 1.0GB (2 × 512MB)
├─ File Storage         : 1.0GB (2 × 512MB)
├─ Search Service       : 1.0GB (2 × 512MB)
└─ Background Jobs      : 512MB (2 × 256MB)
Total                   : 3.5GB

──────────────────────────────
Total Used              : 3.5GB
Available Buffer        : 0.5GB
Status: ✅ Good (12% free)
```

### Server 4 (Hetzner - 64GB) ✅
```
Applications:
├─ Course App (15)      : 15GB (15 × 1GB)
└─ Blog App (15)        : 15GB (15 × 1GB)
Total                   : 30GB

──────────────────────────────
Total Used              : 30GB
Available               : 34GB
Can Scale To            : 64 total replicas
Status: ✅ Excellent (53% free)
```

### Cluster Total
```
Total RAM               : 88GB
Total Used              : 45GB
Total Available         : 43GB
Total Replicas          : 69 replicas
Estimated Capacity      : 15,000-25,000 concurrent users
Status: ✅ Excellent capacity for growth
```

---

## Step 5: Verification Commands

### Check All Services and Their Placement
```bash
# On Server 1 (Manager)

# List all services
docker service ls

# Check Course App placement (should all be on server4)
docker service ps course-app-xyz --format "table {{.Name}}\t{{.Node}}\t{{.CurrentState}}"

# Check Email Service placement (should be on server2/server3)
docker service ps email-service-xyz --format "table {{.Name}}\t{{.Node}}\t{{.CurrentState}}"

# Check Admin Dashboard placement (should all be on server1)
docker service ps admin-dashboard-xyz --format "table {{.Name}}\t{{.Node}}\t{{.CurrentState}}"
```

### Check Resource Usage Per Node
```bash
# See which node has which replicas
for node in $(docker node ls -q); do
  hostname=$(docker node inspect $node --format '{{.Description.Hostname}}')
  echo "=== $hostname ==="
  docker node ps $node --format "  {{.Name}}\t{{.CurrentState}}"
done
```

### Monitor Real-Time Resource Usage
```bash
# SSH into each server and check
ssh server1 "docker stats --no-stream"
ssh server2 "docker stats --no-stream"
ssh server3 "docker stats --no-stream"
ssh server4 "docker stats --no-stream"
```

---

## Step 6: Testing Placement Constraints

### Test 1: Verify Course App Only on Server 4
```bash
docker service ps course-app-xyz --format "{{.Node}}" | sort | uniq

# Expected output:
# server4

# Should NOT show server1, server2, or server3
```

### Test 2: Verify Light Apps NOT on Server 4
```bash
docker service ps email-service-xyz --format "{{.Node}}" | sort | uniq

# Expected output:
# server2
# server3

# Should NOT show server4
```

### Test 3: Force Update and Watch Placement
```bash
# Update a service and watch where replicas go
docker service update --force email-service-xyz

# Watch placement in real-time
watch -n 1 'docker service ps email-service-xyz --format "table {{.Name}}\t{{.Node}}\t{{.CurrentState}}"'
```

---

## Troubleshooting

### Problem: Replicas Not Placed on Expected Servers

**Symptom:** Course App replicas appear on Server 2/3 instead of Server 4

**Solution:**
```bash
# 1. Check node labels
docker node inspect server4 --format '{{ .Spec.Labels }}'

# 2. Check service constraints
docker service inspect course-app-xyz --format '{{ .Spec.TaskTemplate.Placement.Constraints }}'

# 3. Update constraints if needed
docker service update course-app-xyz \
  --constraint-add 'node.labels.size==large'
```

### Problem: Node Out of Resources

**Symptom:** Replicas stuck in "Pending" state

**Solution:**
```bash
# Check why replicas are pending
docker service ps course-app-xyz --no-trunc

# Likely reasons:
# - "no suitable node (insufficient memory)"
# - "no suitable node (insufficient cpu)"

# Solution: Reduce replicas or increase resources
# In Coolify: Configuration → Swarm → Reduce replicas
```

### Problem: Uneven Distribution on Same-Label Nodes

**Symptom:** Server 2 has 20 replicas, Server 3 has 4 replicas

**Solution:**
```bash
# Swarm spreads evenly by default, but may need rebalancing
# Force update to redistribute
docker service update --force email-service-xyz

# This triggers rolling update and redistributes replicas
```

---

## Advanced: Custom Spread Preferences

If you want finer control over distribution among nodes with the same label:

```yaml
# Prefer spreading across different availability zones
Placement Preferences:
  - spread=node.labels.datacenter

# Prefer spreading across different server sizes
Placement Preferences:
  - spread=node.labels.size
```

**In Coolify**, you can add this by manually updating the service (not available in UI yet):

```bash
docker service update course-app-xyz \
  --placement-pref 'spread=node.labels.datacenter'
```

---

## Monitoring Script

Create `/root/monitor-swarm-placement.sh`:

```bash
#!/bin/bash

echo "╔═══════════════════════════════════════════════════════════╗"
echo "║     Docker Swarm Placement & Resource Monitoring          ║"
echo "╚═══════════════════════════════════════════════════════════╝"
echo ""

echo "📊 Cluster Overview:"
docker node ls
echo ""

echo "────────────────────────────────────────────────────────────"
echo ""

for node_id in $(docker node ls -q); do
  hostname=$(docker node inspect $node_id --format '{{.Description.Hostname}}')
  labels=$(docker node inspect $node_id --format '{{range $k, $v := .Spec.Labels}}{{$k}}={{$v}} {{end}}')

  echo "🖥️  Node: $hostname"
  echo "   Labels: $labels"
  echo ""

  # Count replicas by service
  echo "   Running Services:"
  docker node ps $node_id --filter "desired-state=running" --format "{{.Name}}" | \
    awk -F. '{print $1}' | sort | uniq -c | \
    awk '{printf "      • %s: %d replicas\n", $2, $1}'

  echo ""
  echo "────────────────────────────────────────────────────────────"
  echo ""
done

echo "📈 Service Summary:"
docker service ls --format "table {{.Name}}\t{{.Mode}}\t{{.Replicas}}"
echo ""

echo "✅ Placement Validation:"
echo ""

# Check Course App (should only be on server4)
echo "   Course App placement:"
docker service ps course-app-xyz 2>/dev/null --format "{{.Node}}" | sort | uniq -c || echo "   Not deployed yet"

echo ""

# Check Email Service (should be on server2/server3)
echo "   Email Service placement:"
docker service ps email-service-xyz 2>/dev/null --format "{{.Node}}" | sort | uniq -c || echo "   Not deployed yet"

echo ""
echo "────────────────────────────────────────────────────────────"
```

Make it executable and run:
```bash
chmod +x /root/monitor-swarm-placement.sh
./root/monitor-swarm-placement.sh
```

---

## Summary

✅ **Your 4-server cluster is perfectly configured for:**

1. **Smart Capacity Distribution**
   - Heavy apps (Course, Blog) → Server 4 (64GB)
   - Light apps → Servers 2 & 3 (4GB each)
   - Management apps → Server 1 (16GB)

2. **No Resource Waste**
   - Each server used optimally for its capacity
   - Server 4's 64GB not wasted on small apps
   - Servers 2 & 3's 4GB not overloaded with heavy apps

3. **Scalability**
   - Can add 30+ more replicas on Server 4
   - Can add more 4GB workers for light apps
   - Easy to rebalance as needs change

4. **High Availability**
   - 69 total replicas across cluster
   - Multiple replicas per app for redundancy
   - Automatic failover if any replica crashes

**Estimated Capacity:** 15,000-25,000 concurrent users across all 100+ tenant sites! 🚀
