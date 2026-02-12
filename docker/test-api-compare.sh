#!/bin/bash
# Side-by-side API comparison: Yii2 (8081) vs Yii3 (8080)
# Usage: bash docker/test-api-compare.sh

YII2="http://localhost:8081"
YII3="http://localhost:8080"
PASS=0
FAIL=0
SKIP=0

compare() {
    local desc="$1"
    local method="$2"
    local path="$3"
    local data="$4"

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "TEST: $desc"
    echo "  $method $path"

    if [ "$method" = "POST" ]; then
        resp2=$(curl -s -w "\n%{http_code}" -X POST "$YII2$path" -H "Content-Type: application/json" -d "$data" 2>/dev/null)
        resp3=$(curl -s -w "\n%{http_code}" -X POST "$YII3$path" -H "Content-Type: application/json" -d "$data" 2>/dev/null)
    else
        resp2=$(curl -s -w "\n%{http_code}" "$YII2$path" 2>/dev/null)
        resp3=$(curl -s -w "\n%{http_code}" "$YII3$path" 2>/dev/null)
    fi

    code2=$(echo "$resp2" | tail -1)
    body2=$(echo "$resp2" | sed '$d')
    code3=$(echo "$resp3" | tail -1)
    body3=$(echo "$resp3" | sed '$d')

    echo "  Yii2: HTTP $code2"
    echo "  Yii3: HTTP $code3"

    if [ "$code2" = "$code3" ]; then
        echo "  ✅ Status codes match: $code2"

        # Compare JSON structure (keys only, not values like timestamps/tokens)
        keys2=$(echo "$body2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(sorted(d.keys()) if isinstance(d,dict) else type(d).__name__)" 2>/dev/null || echo "parse_error")
        keys3=$(echo "$body3" | python3 -c "import sys,json; d=json.load(sys.stdin); print(sorted(d.keys()) if isinstance(d,dict) else type(d).__name__)" 2>/dev/null || echo "parse_error")

        if [ "$keys2" = "$keys3" ]; then
            echo "  ✅ Response structure matches"
            PASS=$((PASS + 1))
        else
            echo "  ❌ Response structure differs!"
            echo "    Yii2 keys: $keys2"
            echo "    Yii3 keys: $keys3"
            FAIL=$((FAIL + 1))
        fi
    else
        echo "  ❌ Status codes differ!"
        FAIL=$((FAIL + 1))
    fi

    echo "  Yii2 body: $(echo "$body2" | head -c 200)"
    echo "  Yii3 body: $(echo "$body3" | head -c 200)"
    echo ""
}

echo "╔══════════════════════════════════════════════════════╗"
echo "║  API Comparison: Yii2 (8081) vs Yii3 (8080)        ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""

# Wait for services
echo "Waiting for services..."
for i in $(seq 1 30); do
    yii2_ok=$(curl -s -o /dev/null -w "%{http_code}" "$YII2/v1/server/test" 2>/dev/null)
    yii3_ok=$(curl -s -o /dev/null -w "%{http_code}" "$YII3/v1/server/test" 2>/dev/null)
    if [ "$yii2_ok" != "000" ] && [ "$yii3_ok" != "000" ]; then
        echo "Both services are up!"
        break
    fi
    echo "  Waiting... (attempt $i/30) yii2=$yii2_ok yii3=$yii3_ok"
    sleep 2
done
echo ""

# ===== V1 Tests =====
compare "V1 Server Test" "GET" "/v1/server/test"
compare "V1 Public Snapshots" "GET" "/v1/server/public"
compare "V1 Checkin Snapshots" "GET" "/v1/server/checkin"
compare "V1 Tags" "GET" "/v1/server/tags"
compare "V1 Snapshot by ID" "GET" "/v1/server/snapshot?id=1"
compare "V1 Snapshot by verse_id" "GET" "/v1/server/snapshot?verse_id=1"

# Auth tests
compare "V1 Login (valid)" "POST" "/v1/auth/login" '{"username":"testuser","password":"Test1234"}'
compare "V1 Login (invalid password)" "POST" "/v1/auth/login" '{"username":"testuser","password":"wrong"}'
compare "V1 Login (missing user)" "POST" "/v1/auth/login" '{"username":"nonexistent","password":"Test1234"}'
compare "V1 Refresh (invalid token)" "POST" "/v1/auth/refresh" '{"refreshToken":"invalid-token"}'

# Auth-required endpoints without token
compare "V1 Private (no auth)" "GET" "/v1/server/private"
compare "V1 Group (no auth)" "GET" "/v1/server/group"

# ===== V2 Tests =====
compare "V2 System Health" "GET" "/v2/system"
compare "V2 Snapshots (public)" "GET" "/v2/snapshots?scope=public"
compare "V2 Snapshots (checkin)" "GET" "/v2/snapshots?scope=checkin"
compare "V2 Snapshot by ID" "GET" "/v2/snapshots/1"
compare "V2 Tags" "GET" "/v2/tags"

# ===== Health Check =====
compare "Health Check" "GET" "/health"

echo "╔══════════════════════════════════════════════════════╗"
echo "║  Results: ✅ $PASS passed, ❌ $FAIL failed, ⏭ $SKIP skipped  ║"
echo "╚══════════════════════════════════════════════════════╝"
