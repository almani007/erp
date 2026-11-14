FROM node:22-alpine

WORKDIR /var/www/erp

RUN apk add --no-cache git

EXPOSE 5173

# The container idles so setup.sh can run `npm install` / `npm run build`
# inside it. Start the hot-reload dev server on demand with:
#   docker compose exec node npm run dev
CMD ["tail", "-f", "/dev/null"]
