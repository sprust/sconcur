package types

type MongodbCommand int

const (
	MongodbInsertOne              MongodbCommand = 1
	MongodbBulkWrite              MongodbCommand = 2
	MongodbAggregate              MongodbCommand = 3
	MongodbInsertMany             MongodbCommand = 4
	MongodbCountDocuments         MongodbCommand = 5
	MongodbUpdateOne              MongodbCommand = 6
	MongodbFindOne                MongodbCommand = 7
	MongodbCreateIndex            MongodbCommand = 8
	MongodbDeleteOne              MongodbCommand = 9
	MongodbDeleteMany             MongodbCommand = 10
	MongodbUpdateMany             MongodbCommand = 11
	MongodbDrop                   MongodbCommand = 12
	MongodbDropIndex              MongodbCommand = 13
	MongodbFind                   MongodbCommand = 14
	MongodbDistinct               MongodbCommand = 15
	MongodbFindOneAndUpdate       MongodbCommand = 16
	MongodbFindOneAndDelete       MongodbCommand = 17
	MongodbFindOneAndReplace      MongodbCommand = 18
	MongodbReplaceOne             MongodbCommand = 19
	MongodbEstimatedDocumentCount MongodbCommand = 20
	MongodbCreateIndexes          MongodbCommand = 21
	MongodbListIndexes            MongodbCommand = 22
	MongodbListCollections        MongodbCommand = 23
	MongodbListDatabases          MongodbCommand = 24
	MongodbRenameCollection       MongodbCommand = 25
	MongodbRunCommand             MongodbCommand = 26
)
