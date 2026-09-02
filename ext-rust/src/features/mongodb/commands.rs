//! Mirrors ext/internal/features/mongodb/connection/{collection,database}.go.
//!
//! One function per command. The result documents are built by hand with the
//! key names the PHP side reads (`insertedid`, `matchedcount`, …): those are
//! Go's driver result structs marshalled with lowercased field names, so they
//! are a wire contract rather than a naming choice.

use mongodb::bson::{Bson, Document};
use mongodb::options::{IndexOptions, ReturnDocument};
use mongodb::{Client, Collection, Database, IndexModel};

use super::payloads::{Envelope, Params, decode_params};
use super::serializer::{document_from_msgpack, documents_from_msgpack};

pub type Error = String;

/// What a command answers with. `Cursor` is separate because it becomes a
/// streaming state rather than a single result.
pub enum Outcome {
    /// A document, encoded as MessagePack for PHP.
    Document(Document),
    /// A bare string: the counts and index names PHP reads straight off the
    /// task payload without decoding.
    Text(String),
    /// Nothing — `findOne` that matched nothing, a `drop`.
    Empty,
    /// A cursor to be registered as a streaming state.
    Cursor(mongodb::Cursor<Document>, i64),
    /// A finished list of documents, encoded as one batch (listIndexes).
    Batch(Vec<Document>),
}

pub async fn run(
    client: &Client,
    envelope: &Envelope,
) -> Result<Outcome, Error> {
    let database = client.database(&envelope.database);

    match envelope.command.as_str() {
        // Database-level commands.
        "lcl" => list_collections(&database).await,
        "ldb" => list_databases(client).await,
        "rnc" => rename_collection(client, envelope).await,
        "run" => run_command(&database, envelope).await,
        // Collection-level commands.
        _ => {
            let collection = database.collection::<Document>(&envelope.collection);

            run_collection(collection, envelope).await
        }
    }
}

async fn run_collection(
    collection: Collection<Document>,
    envelope: &Envelope,
) -> Result<Outcome, Error> {
    match envelope.command.as_str() {
        "ino" => insert_one(&collection, envelope).await,
        "inm" => insert_many(&collection, envelope).await,
        "bw" => bulk_write(&collection, envelope).await,
        "agg" => aggregate(&collection, envelope).await,
        "cnt" => count_documents(&collection, envelope).await,
        "edc" => estimated_document_count(&collection).await,
        "upo" => update(&collection, envelope, false).await,
        "upm" => update(&collection, envelope, true).await,
        "rpo" => replace_one(&collection, envelope).await,
        "dlo" => delete(&collection, envelope, false).await,
        "dlm" => delete(&collection, envelope, true).await,
        "fno" => find_one(&collection, envelope).await,
        "fnd" => find(&collection, envelope).await,
        "dst" => distinct(&collection, envelope).await,
        "fou" => find_one_and_update(&collection, envelope).await,
        "fod" => find_one_and_delete(&collection, envelope).await,
        "for" => find_one_and_replace(&collection, envelope).await,
        "cix" => create_index(&collection, envelope).await,
        "cxs" => create_indexes(&collection, envelope).await,
        "lix" => list_indexes(&collection).await,
        "dix" => drop_index(&collection, envelope).await,
        "drp" => drop(&collection).await,
        other => Err(format!("unknown command {other}")),
    }
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

async fn insert_one(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let document = document_from_msgpack(&envelope.data)
        .map_err(|error| format!("parse insertOne payload: {error}"))?;

    let outcome = collection
        .insert_one(document)
        .await
        .map_err(|error| error.to_string())?;

    let mut result = Document::new();
    result.insert("insertedid", outcome.inserted_id);

    Ok(Outcome::Document(result))
}

async fn insert_many(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let documents = documents_from_msgpack(&envelope.data)
        .map_err(|error| format!("parse insertMany payload: {error}"))?;

    let outcome = collection
        .insert_many(documents)
        .await
        .map_err(|error| error.to_string())?;

    // Go hands PHP a list of ids in insertion order; the driver keys them by
    // position, so the map is flattened back into that order here.
    let mut ids: Vec<(usize, Bson)> = outcome.inserted_ids.into_iter().collect();
    ids.sort_by_key(|(position, _)| *position);

    let mut result = Document::new();
    result.insert(
        "insertedids",
        Bson::Array(ids.into_iter().map(|(_, id)| id).collect()),
    );

    Ok(Outcome::Document(result))
}

async fn update(
    collection: &Collection<Document>,
    envelope: &Envelope,
    many: bool,
) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let filter = params.filter("f");
    let update = params.document_or_empty("u");

    let hint = params.hint("hn");
    let collation = params.collation("co")?;
    let array_filters = array_filters(&params);

    let outcome = if many {
        let mut action = collection.update_many(filter, update).upsert(params.bool("ou"));

        if let Some(hint) = hint {
            action = action.hint(hint);
        }

        if let Some(collation) = collation {
            action = action.collation(collation);
        }

        if let Some(filters) = array_filters {
            action = action.array_filters(filters);
        }

        action.await
    } else {
        let mut action = collection.update_one(filter, update).upsert(params.bool("ou"));

        if let Some(hint) = hint {
            action = action.hint(hint);
        }

        if let Some(collation) = collation {
            action = action.collation(collation);
        }

        if let Some(filters) = array_filters {
            action = action.array_filters(filters);
        }

        action.await
    }
    .map_err(|error| error.to_string())?;

    Ok(Outcome::Document(update_result(
        outcome.matched_count,
        outcome.modified_count,
        outcome.upserted_id,
    )))
}

async fn replace_one(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let filter = params.filter("f");
    let replacement = params.document_or_empty("r");

    let outcome = collection
        .replace_one(filter, replacement)
        .upsert(params.bool("ou"))
        .await
        .map_err(|error| error.to_string())?;

    Ok(Outcome::Document(update_result(
        outcome.matched_count,
        outcome.modified_count,
        outcome.upserted_id,
    )))
}

async fn delete(
    collection: &Collection<Document>,
    envelope: &Envelope,
    many: bool,
) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let filter = params.filter("f");

    let hint = params.hint("hn");
    let collation = params.collation("co")?;

    let outcome = if many {
        let mut action = collection.delete_many(filter);

        if let Some(hint) = hint {
            action = action.hint(hint);
        }

        if let Some(collation) = collation {
            action = action.collation(collation);
        }

        action.await
    } else {
        let mut action = collection.delete_one(filter);

        if let Some(hint) = hint {
            action = action.hint(hint);
        }

        if let Some(collation) = collation {
            action = action.collation(collation);
        }

        action.await
    }
    .map_err(|error| error.to_string())?;

    let mut result = Document::new();
    result.insert("deletedcount", outcome.deleted_count as i64);

    Ok(Outcome::Document(result))
}

async fn bulk_write(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let namespace = mongodb::Namespace {
        db: envelope.database.clone(),
        coll: envelope.collection.clone(),
    };

    let operations = super::bulk::parse(&envelope.data, &namespace)?;

    super::bulk::execute(collection, operations).await
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

async fn count_documents(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let filter = document_from_msgpack(&envelope.data)
        .map_err(|error| format!("parse countDocuments payload: {error}"))?;

    let count = collection
        .count_documents(filter)
        .await
        .map_err(|error| error.to_string())?;

    Ok(Outcome::Text(count.to_string()))
}

async fn estimated_document_count(collection: &Collection<Document>) -> Result<Outcome, Error> {
    let count = collection
        .estimated_document_count()
        .await
        .map_err(|error| error.to_string())?;

    Ok(Outcome::Text(count.to_string()))
}

async fn find_one(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let filter = params.filter("f");

    let mut action = collection.find_one(filter);

    if let Some(projection) = params.document("op") {
        action = action.projection(projection);
    }

    if let Some(hint) = params.hint("hn") {
        action = action.hint(hint);
    }

    if let Some(collation) = params.collation("co")? {
        action = action.collation(collation);
    }

    match action.await.map_err(|error| error.to_string())? {
        Some(document) => Ok(Outcome::Document(document)),
        None => Ok(Outcome::Empty),
    }
}

async fn find(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let filter = params.filter("f");

    let batch_size = params.int("bs");

    let mut action = collection.find(filter);

    if let Some(projection) = params.document("op") {
        action = action.projection(projection);
    }

    if let Some(sort) = params.document("s") {
        action = action.sort(sort);
    }

    let limit = params.int("l");

    if limit > 0 {
        action = action.limit(limit);
    }

    let skip = params.int("sk");

    if skip > 0 {
        action = action.skip(skip as u64);
    }

    if batch_size > 0 {
        action = action.batch_size(batch_size as u32);
    }

    if let Some(hint) = params.hint("hn") {
        action = action.hint(hint);
    }

    if let Some(collation) = params.collation("co")? {
        action = action.collation(collation);
    }

    let cursor = action.await.map_err(|error| error.to_string())?;

    Ok(Outcome::Cursor(cursor, batch_size))
}

async fn aggregate(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let pipeline = params.documents("p");

    let batch_size = params.int("bs");

    let mut action = collection.aggregate(pipeline);

    if batch_size > 0 {
        action = action.batch_size(batch_size as u32);
    }

    let cursor = action
        .await
        .map_err(|error| error.to_string())?
        .with_type::<Document>();

    Ok(Outcome::Cursor(cursor, batch_size))
}

async fn distinct(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let field = params.string("fn");
    let filter = params.filter("f");

    let mut action = collection.distinct(field, filter);

    if let Some(collation) = params.collation("co")? {
        action = action.collation(collation);
    }

    let values = action.await.map_err(|error| error.to_string())?;

    let mut result = Document::new();
    result.insert("values", Bson::Array(values));

    Ok(Outcome::Document(result))
}

// ---------------------------------------------------------------------------
// Find-and-modify
// ---------------------------------------------------------------------------

async fn find_one_and_update(
    collection: &Collection<Document>,
    envelope: &Envelope,
) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let filter = params.filter("f");
    let update = params.document_or_empty("u");

    let mut action = collection
        .find_one_and_update(filter, update)
        .upsert(params.bool("ou"))
        .return_document(return_document(&params));

    if let Some(projection) = params.document("op") {
        action = action.projection(projection);
    }

    if let Some(hint) = params.hint("hn") {
        action = action.hint(hint);
    }

    if let Some(collation) = params.collation("co")? {
        action = action.collation(collation);
    }

    if let Some(filters) = array_filters(&params) {
        action = action.array_filters(filters);
    }

    optional_document_outcome(action.await)
}

async fn find_one_and_delete(
    collection: &Collection<Document>,
    envelope: &Envelope,
) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let filter = params.filter("f");

    let mut action = collection.find_one_and_delete(filter);

    if let Some(projection) = params.document("op") {
        action = action.projection(projection);
    }

    optional_document_outcome(action.await)
}

async fn find_one_and_replace(
    collection: &Collection<Document>,
    envelope: &Envelope,
) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let filter = params.filter("f");
    let replacement = params.document_or_empty("r");

    let mut action = collection
        .find_one_and_replace(filter, replacement)
        .upsert(params.bool("ou"))
        .return_document(return_document(&params));

    if let Some(projection) = params.document("op") {
        action = action.projection(projection);
    }

    optional_document_outcome(action.await)
}

// ---------------------------------------------------------------------------
// Indexes and collection management
// ---------------------------------------------------------------------------

async fn create_index(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let keys = params.document_or_empty("k");

    let name = params.string("n");

    let model = if name.is_empty() {
        IndexModel::builder().keys(keys).build()
    } else {
        IndexModel::builder()
            .keys(keys)
            .options(IndexOptions::builder().name(name).build())
            .build()
    };

    let outcome = collection
        .create_index(model)
        .await
        .map_err(|error| error.to_string())?;

    Ok(Outcome::Text(outcome.index_name))
}

async fn create_indexes(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    // dt is {ix: [{k, n}, ...]}, read inline the way Go reads it.
    let entries = params.documents("ix");

    let mut models = Vec::with_capacity(entries.len());

    for entry in entries {
        let keys = match entry.get("k") {
            Some(Bson::Document(keys)) => keys.clone(),
            _ => return Err("createIndexes entry without keys".to_string()),
        };

        let name = match entry.get("n") {
            Some(Bson::String(name)) if !name.is_empty() => Some(name.clone()),
            _ => None,
        };

        models.push(match name {
            Some(name) => IndexModel::builder()
                .keys(keys)
                .options(IndexOptions::builder().name(name).build())
                .build(),
            None => IndexModel::builder().keys(keys).build(),
        });
    }

    let outcome = collection
        .create_indexes(models)
        .await
        .map_err(|error| error.to_string())?;

    let mut result = Document::new();
    result.insert(
        "names",
        Bson::Array(outcome.index_names.into_iter().map(Bson::String).collect()),
    );

    Ok(Outcome::Document(result))
}

async fn list_indexes(collection: &Collection<Document>) -> Result<Outcome, Error> {
    let mut cursor = collection
        .list_indexes()
        .await
        .map_err(|error| error.to_string())?;

    let mut documents = Vec::new();

    while cursor.advance().await.map_err(|error| error.to_string())? {
        let model = cursor
            .deserialize_current()
            .map_err(|error| error.to_string())?;

        documents.push(
            mongodb::bson::serialize_to_document(&model).map_err(|error| error.to_string())?,
        );
    }

    Ok(Outcome::Batch(documents))
}

async fn drop_index(collection: &Collection<Document>, envelope: &Envelope) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;
    let name = params.string("n");

    collection
        .drop_index(name.clone())
        .await
        .map_err(|error| error.to_string())?;

    Ok(Outcome::Text(name))
}

async fn drop(collection: &Collection<Document>) -> Result<Outcome, Error> {
    collection.drop().await.map_err(|error| error.to_string())?;

    Ok(Outcome::Empty)
}

// ---------------------------------------------------------------------------
// Database-level
// ---------------------------------------------------------------------------

async fn list_collections(database: &Database) -> Result<Outcome, Error> {
    let names = database
        .list_collection_names()
        .await
        .map_err(|error| error.to_string())?;

    let mut result = Document::new();
    result.insert("names", Bson::Array(names.into_iter().map(Bson::String).collect()));

    Ok(Outcome::Document(result))
}

async fn list_databases(client: &Client) -> Result<Outcome, Error> {
    let names = client
        .list_database_names()
        .await
        .map_err(|error| error.to_string())?;

    let mut result = Document::new();
    result.insert("names", Bson::Array(names.into_iter().map(Bson::String).collect()));

    Ok(Outcome::Document(result))
}

async fn rename_collection(client: &Client, envelope: &Envelope) -> Result<Outcome, Error> {
    let params = decode_params(&envelope.data)?;

    let mut command = Document::new();
    command.insert(
        "renameCollection",
        format!("{}.{}", envelope.database, envelope.collection),
    );
    command.insert("to", format!("{}.{}", envelope.database, params.string("t")));

    if params.bool("dt") {
        command.insert("dropTarget", true);
    }

    // renameCollection is an admin command whatever database the collection is in.
    client
        .database("admin")
        .run_command(command)
        .await
        .map_err(|error| error.to_string())?;

    Ok(Outcome::Empty)
}

async fn run_command(database: &Database, envelope: &Envelope) -> Result<Outcome, Error> {
    let command = document_from_msgpack(&envelope.data)
        .map_err(|error| format!("parse runCommand payload: {error}"))?;

    let outcome = database
        .run_command(command)
        .await
        .map_err(|error| error.to_string())?;

    Ok(Outcome::Document(outcome))
}

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

fn update_result(matched: u64, modified: u64, upserted_id: Option<Bson>) -> Document {
    let mut result = Document::new();

    result.insert("matchedcount", matched as i64);
    result.insert("modifiedcount", modified as i64);
    result.insert(
        "upsertedcount",
        if upserted_id.is_some() { 1_i64 } else { 0_i64 },
    );
    result.insert("upsertedid", upserted_id.unwrap_or(Bson::Null));

    result
}

fn optional_document_outcome(
    outcome: mongodb::error::Result<Option<Document>>,
) -> Result<Outcome, Error> {
    match outcome.map_err(|error| error.to_string())? {
        Some(document) => Ok(Outcome::Document(document)),
        None => Ok(Outcome::Empty),
    }
}

/// The arrayFilters option: absent when the PHP side sent none.
fn array_filters(params: &Params) -> Option<Vec<Document>> {
    let filters = params.documents("af");

    if filters.is_empty() {
        return None;
    }

    Some(filters)
}

/// PHP sends a boolean: true means "the document after the update".
fn return_document(params: &Params) -> ReturnDocument {
    if params.bool("rd") {
        ReturnDocument::After
    } else {
        ReturnDocument::Before
    }
}
