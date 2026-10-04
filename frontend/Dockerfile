# ==============================================================================
# TransactIQ Frontend Dockerfile
# Multi-stage build: Node.js 20 build stage + Nginx Alpine static server
# ==============================================================================

# Stage 1: Build the React 19 + TypeScript Vite application
FROM node:20-alpine AS builder

WORKDIR /app

# Copy package descriptors first to cache npm dependencies layer
COPY package.json package-lock.json ./

RUN npm ci

# Copy application source code
COPY . .

# Build the production bundle using Vite and TypeScript compiler
RUN npm run build

# Stage 2: Serve the compiled application via lightweight Nginx Alpine
FROM nginx:alpine

# Remove default Nginx static files
RUN rm -rf /usr/share/nginx/html/*

# Copy built distribution assets from builder stage
COPY --from=builder /app/dist /usr/share/nginx/html

# Copy custom Nginx configuration for SPA routing and API proxy
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Expose HTTP port
EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
