# Using Coolify's Built-in Replica System (Docker Swarm)

Good news! **Coolify already has a replica/scaling system** using Docker Swarm. Here's how to use it for your multi-tenant SaaS applications.

---

## 🎉 **What You Have**

Coolify supports **horizontal scaling via Docker Swarm**:

- ✅ **Application Replicas**: Scale to multiple instances
- ✅ **Load Balancing**: Automatic via Traefik
- ✅ **Health Checks**: Automatic failover
- ✅ **Rolling Updates**: Zero-downtime deployments
- ✅ **Placement Constraints**: Control where replicas run

**Located in your codebase**:
- Database field: `swarm_replicas` (line 91 in Application.php)
- Livewire UI: `app/Livewire/Project/Application/Swarm.php`
- Settings stored per application

---

## 📋 **Quick Setup Guide**

### **Option 1: Single Server with Multiple Replicas** (Simplest)

Perfect for starting out with 100+ tenants on one powerful server.

#### **Step 1: Initialize Docker Swarm**

SSH into your server:

```bash
# Initialize swarm mode
docker swarm init

# Verify swarm is active
docker node ls
```

You should see output like:
```
ID                    HOSTNAME   STATUS    AVAILABILITY   MANAGER STATUS
abc123def456 *        server1    Ready     Active         Leader
```

#### **Step 2: Configure Server in Coolify**

1. Navigate to **Servers** → Your Server
2. Check **"Is Swarm Manager"** checkbox
3. Save

Or via database:
```bash
docker exec -it coolify-db psql -U coolify -d coolify
UPDATE server_settings SET is_swarm_manager = true WHERE server_id = YOUR_SERVER_ID;
\q
```

#### **Step 3: Configure Application Replicas**

**Via UI**:
1. Go to your application (e.g., course-app)
2. Navigate to **Configuration** → **Swarm**
3. Set **Swarm Replicas**: `5` (or desired number)
4. Click **Save**
5. **Redeploy** the application

**Via API** (for automation):
```bash
curl -X PATCH "https://coolify.example.com/api/v1/applications/{uuid}" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "swarm_replicas": 5
  }'
```

#### **Step 4: Verify Replicas Are Running**

```bash
# Check running services
docker service ls

# Should show something like:
# ID            NAME              REPLICAS    IMAGE
# abc123def     course-app-xyz    5/5         course-app:latest

# Check individual replicas
docker service ps course-app-xyz

# Should show 5 tasks running:
# ID     NAME                IMAGE              NODE      STATUS
# 1      course-app-xyz.1    course-app:latest  server1   Running
# 2      course-app-xyz.2    course-app:latest  server1   Running
# 3      course-app-xyz.3    course-app:latest  server1   Running
# 4      course-app-xyz.4    course-app:latest  server1   Running
# 5      course-app-xyz.5    course-app:latest  server1   Running
```

---

### **Option 2: Multi-Server Docker Swarm Cluster** (For Growth)

When one server isn't enough, scale across multiple servers.

#### **Architecture**

```
[Swarm Manager] ← Coolify connects here
      │
      ├─► [Worker Node 1] (runs replicas)
      ├─► [Worker Node 2] (runs replicas)
      └─► [Worker Node 3] (runs replicas)
```

#### **Step 1: Set Up Swarm Manager**

On your main server (Manager):
```bash
# Initialize swarm
docker swarm init --advertise-addr YOUR_SERVER_IP

# Save the join token
docker swarm join-token worker
```

Copy the output token (looks like):
```
docker swarm join --token SWMTKN-1-xxx... YOUR_SERVER_IP:2377
```

#### **Step 2: Add Worker Nodes**

On each additional server (Workers):
```bash
# Use the token from Step 1
docker swarm join --token SWMTKN-1-xxx... MANAGER_IP:2377

# Verify on manager
docker node ls
```

You should see:
```
ID                  HOSTNAME   STATUS   AVAILABILITY   MANAGER STATUS
abc123 *            manager    Ready    Active         Leader
def456              worker1    Ready    Active
ghi789              worker2    Ready    Active
```

#### **Step 3: Configure in Coolify**

**Manager Server**:
1. Servers → Manager Server
2. Check **"Is Swarm Manager"**
3. Save

**Worker Servers** (Add each to Coolify):
1. Servers → Add Server → Worker Server
2. Check **"Is Swarm Worker"**
3. Select **Swarm Manager**: (choose your manager)
4. Save

#### **Step 4: Deploy Application with Replicas**

Same as Option 1, but now replicas distribute across all nodes!

```bash
# Check where replicas are running
docker service ps course-app-xyz

# Output shows distribution:
# NAME                NODE      STATUS
# course-app-xyz.1    manager   Running
# course-app-xyz.2    worker1   Running
# course-app-xyz.3    worker2   Running
# course-app-xyz.4    manager   Running
# course-app-xyz.5    worker1   Running
```

---

## ⚙️ **Configuration Options**

### **Replica Count**

Start conservatively and scale up:

```
Small tenant load:  3 replicas
Medium tenant load: 5-7 replicas
High tenant load:   10+ replicas
```

**Formula**: `replicas = (peak_concurrent_users / 200) + 2`

Example:
- 1000 concurrent users → 7 replicas
- 2000 concurrent users → 12 replicas

### **Placement Constraints**

Control where replicas run. In the **Swarm** settings:

**Only Workers** (recommended for production):
```
Check "Is Swarm Only Worker Nodes"
```

This keeps manager node free for orchestration.

**Custom Constraints** (advanced):
```yaml
# Example: Only nodes with SSD
node.labels.storage == ssd

# Example: Avoid specific node
node.hostname != old-server

# Example: Only nodes in specific datacenter
node.labels.datacenter == us-east
```

---

## 🔄 **How Load Balancing Works**

Traefik (Coolify's proxy) automatically load balances between replicas:

```
User Request → Traefik → Round-robin across replicas
                  ├─► Replica 1
                  ├─► Replica 2
                  ├─► Replica 3
                  ├─► Replica 4
                  └─► Replica 5
```

### **Session Stickiness** (Important for Multi-Tenant!)

If you need users to stick to the same replica (for sessions):

**Add to your Docker labels** (via Custom Labels in Coolify):
```
traefik.http.services.{service-name}.loadbalancer.sticky.cookie.name=sticky
traefik.http.services.{service-name}.loadbalancer.sticky.cookie.httpOnly=true
traefik.http.services.{service-name}.loadbalancer.sticky.cookie.secure=true
```

---

## 📊 **Monitoring Replicas**

### **Check Replica Health**

```bash
# Service status
docker service ls

# Detailed replica status
docker service ps course-app-xyz --no-trunc

# Logs from all replicas
docker service logs course-app-xyz --follow

# Logs from specific replica
docker service logs course-app-xyz.3
```

### **Resource Usage Per Replica**

```bash
# Stats for all containers
docker stats

# Filter for your service
docker stats $(docker ps -q --filter "name=course-app")
```

### **Replica Distribution**

```bash
# See which nodes have which replicas
docker service ps course-app-xyz --format "table {{.Name}}\t{{.Node}}\t{{.CurrentState}}"
```

---

## 🚀 **Scaling Up/Down**

### **Manual Scaling**

**Via UI**:
1. Go to application
2. Configuration → Swarm
3. Change **Swarm Replicas**: `10`
4. Save
5. **No need to redeploy** - scales instantly!

**Via CLI on Server**:
```bash
# Scale to 10 replicas
docker service scale course-app-xyz=10

# Scales instantly without downtime!
```

**Via API**:
```bash
curl -X PATCH "https://coolify.example.com/api/v1/applications/{uuid}" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"swarm_replicas": 10}'
```

### **Auto-Scaling** (Advanced)

Create a monitoring script:

```bash
#!/bin/bash
# auto-scale.sh

APP_UUID="your-app-uuid"
COOLIFY_TOKEN="your-api-token"
SERVICE_NAME="course-app-xyz"

# Get current CPU usage
CPU_USAGE=$(docker stats $SERVICE_NAME --no-stream --format "{{.CPUPerc}}" | sed 's/%//')

# Scale up if CPU > 70%
if (( $(echo "$CPU_USAGE > 70" | bc -l) )); then
    CURRENT_REPLICAS=$(docker service ls --filter "name=$SERVICE_NAME" --format "{{.Replicas}}" | cut -d'/' -f1)
    NEW_REPLICAS=$((CURRENT_REPLICAS + 2))

    echo "Scaling up to $NEW_REPLICAS replicas"

    curl -X PATCH "https://coolify.example.com/api/v1/applications/$APP_UUID" \
      -H "Authorization: Bearer $COOLIFY_TOKEN" \
      -H "Content-Type: application/json" \
      -d "{\"swarm_replicas\": $NEW_REPLICAS}"
fi

# Scale down if CPU < 30% and replicas > 3
if (( $(echo "$CPU_USAGE < 30" | bc -l) )); then
    CURRENT_REPLICAS=$(docker service ls --filter "name=$SERVICE_NAME" --format "{{.Replicas}}" | cut -d'/' -f1)

    if [ "$CURRENT_REPLICAS" -gt 3 ]; then
        NEW_REPLICAS=$((CURRENT_REPLICAS - 1))

        echo "Scaling down to $NEW_REPLICAS replicas"

        curl -X PATCH "https://coolify.example.com/api/v1/applications/$APP_UUID" \
          -H "Authorization: Bearer $COOLIFY_TOKEN" \
          -H "Content-Type: application/json" \
          -d "{\"swarm_replicas\": $NEW_REPLICAS}"
    fi
fi
```

Run via cron:
```bash
# Check every 5 minutes
*/5 * * * * /path/to/auto-scale.sh
```

---

## 🛠️ **Troubleshooting**

### **Replicas Not Starting**

```bash
# Check service events
docker service ps course-app-xyz --no-trunc

# Common issues:
# - Resource constraints (not enough CPU/RAM)
# - Image pull failures
# - Health check failures
```

**Solution**:
```bash
# Increase resources in Coolify UI
Configuration → Advanced → Resource Limits
CPU: 1.0 → 0.5 (per replica)
Memory: 1G → 512M (per replica)
```

### **Uneven Load Distribution**

```bash
# Check if all replicas are running
docker service ps course-app-xyz

# If some are stuck in "Preparing" or "Starting":
docker service logs course-app-xyz --since 10m
```

**Solution**: Check health check settings - they might be too strict.

### **Can't Scale Beyond X Replicas**

**Issue**: Server resources exhausted

```bash
# Check server resources
docker node ls
docker node inspect NODEID | grep -A 5 "Resources"
```

**Solution**: Add more worker nodes or upgrade server resources.

---

## 💡 **Best Practices for Multi-Tenant SaaS**

### **1. Start with 5 Replicas**

Good balance between redundancy and resource usage:
```
Configuration → Swarm → Replicas: 5
```

### **2. Use Resource Limits**

Prevent one replica from hogging resources:
```
Configuration → Advanced
├─ CPU: 0.5 cores per replica
└─ Memory: 512MB per replica
```

With 5 replicas: 2.5 CPU cores, 2.5GB RAM total

### **3. Enable Health Checks**

Automatic failover for unhealthy replicas:
```
Configuration → Health Check
├─ Enabled: Yes
├─ Path: /health
├─ Interval: 10s
└─ Retries: 3
```

### **4. Configure Rolling Updates**

Zero-downtime deployments:
```yaml
# Swarm automatically does rolling updates
# When you deploy:
# 1. New replica starts
# 2. Health check passes
# 3. Old replica stops
# 4. Repeat for each replica
```

### **5. Monitor Replica Health**

Set up alerts:
```bash
# Check for unhealthy replicas
docker service ps course-app-xyz --filter "desired-state=running" | grep -v "Running"

# If any found, alert admins
```

---

## 📈 **Capacity Planning**

### **Server Sizing for 100+ Tenants**

**Single Server Approach**:
```
Server: 16 CPU cores, 32GB RAM
Application: 5 replicas @ 0.5 CPU, 512MB each
Capacity: ~2.5 cores, 2.5GB RAM
Remaining: 13.5 cores, 29.5GB for database, Redis, etc.

Expected load: 2000-5000 concurrent users
```

**Multi-Server Approach**:
```
Manager: 8 CPU, 16GB RAM (light load, orchestration only)
Worker 1: 16 CPU, 32GB RAM (7-10 replicas)
Worker 2: 16 CPU, 32GB RAM (7-10 replicas)
Worker 3: 16 CPU, 32GB RAM (7-10 replicas)

Total: 21-30 replicas
Expected load: 6000-10,000 concurrent users
```

---

## 🎯 **Recommended Setup for Your Use Case**

Based on your **100+ sites with multiple users each**:

### **Phase 1: Single Server** (0-50 tenants)
```
Server: 8 cores, 16GB RAM
Course App: 3 replicas
Blog App: 3 replicas
Database: PostgreSQL with read replicas
Redis: 1 instance for caching
```

### **Phase 2: Scale Up** (50-150 tenants)
```
Server: 16 cores, 32GB RAM
Course App: 5-7 replicas
Blog App: 5-7 replicas
Database: Primary + 2 read replicas
Redis: Redis Cluster
```

### **Phase 3: Multi-Server** (150+ tenants)
```
Swarm Manager: 8 cores, 16GB
Worker 1: 16 cores, 32GB (Course App replicas)
Worker 2: 16 cores, 32GB (Blog App replicas)
Database Server: 16 cores, 32GB (dedicated)
Redis Server: 8 cores, 16GB (dedicated)

Total replicas: 15-20 per app
```

---

## ✅ **Quick Checklist**

- [ ] Initialize Docker Swarm on server
- [ ] Configure server as Swarm Manager in Coolify
- [ ] Set application replicas (start with 3-5)
- [ ] Enable health checks
- [ ] Configure resource limits per replica
- [ ] Test failover (stop a replica, see if traffic continues)
- [ ] Monitor replica distribution
- [ ] Set up alerts for replica failures
- [ ] Document scaling thresholds
- [ ] Create runbook for scaling operations

---

## 🆘 **Getting Help**

If replicas aren't working:

```bash
# 1. Check swarm status
docker info | grep Swarm

# Should show: Swarm: active

# 2. Check service status
docker service ls

# 3. Check service logs
docker service logs YOUR_SERVICE_NAME --tail 100

# 4. Check Coolify logs
docker logs coolify --tail 100

# 5. Check Traefik logs
docker logs coolify-proxy --tail 100
```

---

**You're already set up for scaling!** 🎉 Just enable Swarm mode and set your replica count. No additional infrastructure needed!
