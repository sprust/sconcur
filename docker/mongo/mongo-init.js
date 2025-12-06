db = db.getSiblingDB('admin');

db.createUser({
  user: process.env["MONGO_ADMIN_USERNAME"],
  pwd:  process.env["MONGO_ADMIN_PASSWORD"],
  roles: [{ role: "dbAdminAnyDatabase", db: "admin"}],
});
