//! Mirrors serializer.UnmarshalBulkWriteModels and Collection.BulkWrite.
//!
//! The payload is a document whose elements are `{type, model}` pairs. This
//! driver exposes bulk write at the client level (`Client::bulk_write`), which
//! is the server 8.0 `bulkWrite` command, rather than per collection. It is
//! still one round trip, so the shape of the operation is what PHP expects; what
//! it costs is a hard requirement on a 8.0+ server, which the project runs.

use mongodb::bson::{Bson, Document};
use mongodb::options::{
    DeleteManyModel, DeleteOneModel, InsertOneModel, ReplaceOneModel, UpdateManyModel,
    UpdateOneModel, WriteModel,
};
use mongodb::{Collection, Namespace};

use super::commands::{Error, Outcome};
use super::serializer::document_from_msgpack;

pub fn parse(data: &[u8], namespace: &Namespace) -> Result<Vec<WriteModel>, Error> {
    if data.is_empty() {
        return Ok(Vec::new());
    }

    let document = document_from_msgpack(data)
        .map_err(|error| format!("error reading bulkWrite payload: {error}"))?;

    let mut models = Vec::with_capacity(document.len());

    for (key, value) in &document {
        let Bson::Document(wrapper) = value else {
            return Err(format!("bulkWrite operation \"{key}\" is not a document"));
        };

        let Some(Bson::String(operation)) = wrapper.get("type") else {
            return Err(format!("bulkWrite operation \"{key}\" has no string type"));
        };

        let Some(Bson::Document(model)) = wrapper.get("model") else {
            return Err(format!("{operation} [model is not a document]"));
        };

        models.push(build(operation, model, namespace)?);
    }

    Ok(models)
}

fn build(operation: &str, model: &Document, namespace: &Namespace) -> Result<WriteModel, Error> {
    match operation {
        "insertOne" => Ok(InsertOneModel::builder()
            .namespace(namespace.clone())
            .document(field(model, "document", operation, "document")?)
            .build()
            .into()),
        "updateOne" => Ok(UpdateOneModel::builder()
            .namespace(namespace.clone())
            .filter(field(model, "filter", operation, "filter")?)
            .update(field(model, "update", operation, "update")?)
            .upsert(upsert(model))
            .build()
            .into()),
        "updateMany" => Ok(UpdateManyModel::builder()
            .namespace(namespace.clone())
            .filter(field(model, "filter", operation, "filter")?)
            .update(field(model, "update", operation, "update")?)
            .upsert(upsert(model))
            .build()
            .into()),
        "replaceOne" => Ok(ReplaceOneModel::builder()
            .namespace(namespace.clone())
            .filter(field(model, "filter", operation, "filter")?)
            .replacement(field(model, "replacement", operation, "replacement")?)
            .upsert(upsert(model))
            .build()
            .into()),
        "deleteOne" => Ok(DeleteOneModel::builder()
            .namespace(namespace.clone())
            .filter(field(model, "filter", operation, "filter")?)
            .build()
            .into()),
        "deleteMany" => Ok(DeleteManyModel::builder()
            .namespace(namespace.clone())
            .filter(field(model, "filter", operation, "filter")?)
            .build()
            .into()),
        other => Err(format!("unknown type of model: {other}")),
    }
}

/// An empty filter/update/document is encoded by the PHP side as an empty
/// array, which reaches BSON as an empty document — so a missing key is the
/// error, an empty one is not.
fn field(model: &Document, key: &str, operation: &str, label: &str) -> Result<Document, Error> {
    match model.get(key) {
        Some(Bson::Document(document)) => Ok(document.clone()),
        Some(Bson::Array(items)) if items.is_empty() => Ok(Document::new()),
        _ => Err(format!("{operation} {label} [missing or not a document]")),
    }
}

fn upsert(model: &Document) -> Option<bool> {
    match model.get("upsert") {
        Some(Bson::Boolean(flag)) => Some(*flag),
        _ => None,
    }
}

pub async fn execute(
    collection: &Collection<Document>,
    models: Vec<WriteModel>,
) -> Result<Outcome, Error> {
    let mut result = Document::new();

    if models.is_empty() {
        result.insert("insertedcount", 0_i64);
        result.insert("matchedcount", 0_i64);
        result.insert("modifiedcount", 0_i64);
        result.insert("deletedcount", 0_i64);
        result.insert("upsertedcount", 0_i64);
        result.insert("upsertedids", Document::new());

        return Ok(Outcome::Document(result));
    }

    // Verbose, not summary: PHP reads upsertedIds off the result, and only the
    // verbose form carries them.
    let outcome = collection
        .client()
        .bulk_write(models)
        .verbose_results()
        .await
        .map_err(|error| error.to_string())?;

    let mut upserted = Document::new();

    for (index, update) in &outcome.update_results {
        if let Some(id) = &update.upserted_id {
            upserted.insert(index.to_string(), id.clone());
        }
    }

    result.insert("insertedcount", outcome.summary.inserted_count);
    result.insert("matchedcount", outcome.summary.matched_count);
    result.insert("modifiedcount", outcome.summary.modified_count);
    result.insert("deletedcount", outcome.summary.deleted_count);
    result.insert("upsertedcount", outcome.summary.upserted_count);
    result.insert("upsertedids", upserted);

    Ok(Outcome::Document(result))
}
