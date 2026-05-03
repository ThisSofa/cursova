import os
from contextlib import asynccontextmanager
from typing import List, Optional

from fastapi import Depends, FastAPI, HTTPException
from pydantic import BaseModel
from sqlalchemy import Column, Integer, String, Text, create_engine, distinct
from sqlalchemy.orm import Session, declarative_base, sessionmaker

# -- Database Configuration --
DATABASE_URL = os.getenv("DATABASE_URL", "sqlite:///./inventory.db")
engine = create_engine(DATABASE_URL)
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)
Base = declarative_base()

# -- Models --
class Item(Base):
    __tablename__ = "items"
    
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(255), index=True)
    category = Column(String(255), index=True)
    status = Column(String(100))
    quantity = Column(Integer, default=1)
    description = Column(Text, nullable=True)

# -- Pydantic Schemas --
class ItemBase(BaseModel):
    name: str
    category: str
    status: str
    quantity: int = 1
    description: Optional[str] = None

class ItemCreate(ItemBase):
    pass

class ItemResponse(ItemBase):
    id: int

    class Config:
        from_attributes = True


class ItemBulkDeleteRequest(BaseModel):
    ids: List[int]


class ItemBulkUpdateRequest(BaseModel):
    ids: List[int]
    category: Optional[str] = None
    status: Optional[str] = None


class BulkActionResponse(BaseModel):
    updated: int = 0
    deleted: int = 0

# -- FastAPI Setup --
@asynccontextmanager
async def lifespan(app: FastAPI):
    # Create tables on startup
    Base.metadata.create_all(bind=engine)
    yield

app = FastAPI(lifespan=lifespan)

# Dependency
def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()

# -- Endpoints --
@app.get("/categories/", response_model=List[str])
def get_categories(db: Session = Depends(get_db)):
    rows = db.query(distinct(Item.category)).order_by(Item.category).all()
    return [r[0] for r in rows if r[0]]

@app.get("/items/", response_model=List[ItemResponse])
def read_items(skip: int = 0, limit: int = 100, category: Optional[str] = None, search: Optional[str] = None, db: Session = Depends(get_db)):
    query = db.query(Item)
    if category:
        query = query.filter(Item.category == category)
    if search:
        query = query.filter(Item.name.ilike(f"%{search}%"))
    
    # Sort categories to meet requirement "сортування даних за категоріями"
    query = query.order_by(Item.category)
    return query.offset(skip).limit(limit).all()


@app.get("/items/{item_id}", response_model=ItemResponse)
def read_item(item_id: int, db: Session = Depends(get_db)):
    db_item = db.query(Item).filter(Item.id == item_id).first()
    if db_item is None:
        raise HTTPException(status_code=404, detail="Item not found")
    return db_item

@app.post("/items/", response_model=ItemResponse)
def create_item(item: ItemCreate, db: Session = Depends(get_db)):
    db_item = Item(**item.model_dump())
    db.add(db_item)
    db.commit()
    db.refresh(db_item)
    return db_item

@app.put("/items/{item_id}", response_model=ItemResponse)
def update_item(item_id: int, item: ItemCreate, db: Session = Depends(get_db)):
    db_item = db.query(Item).filter(Item.id == item_id).first()
    if db_item is None:
        raise HTTPException(status_code=404, detail="Item not found")
        
    for key, value in item.model_dump().items():
        setattr(db_item, key, value)
        
    db.commit()
    db.refresh(db_item)
    return db_item

@app.delete("/items/{item_id}")
def delete_item(item_id: int, db: Session = Depends(get_db)):
    db_item = db.query(Item).filter(Item.id == item_id).first()
    if db_item is None:
        raise HTTPException(status_code=404, detail="Item not found")
        
    db.delete(db_item)
    db.commit()
    return {"message": "Item deleted"}


@app.post("/items/bulk/delete", response_model=BulkActionResponse)
def bulk_delete_items(payload: ItemBulkDeleteRequest, db: Session = Depends(get_db)):
    if not payload.ids:
        return BulkActionResponse(deleted=0)

    deleted = db.query(Item).filter(Item.id.in_(payload.ids)).delete(synchronize_session=False)
    db.commit()
    return BulkActionResponse(deleted=deleted)


@app.post("/items/bulk/update", response_model=BulkActionResponse)
def bulk_update_items(payload: ItemBulkUpdateRequest, db: Session = Depends(get_db)):
    if not payload.ids:
        return BulkActionResponse(updated=0)

    updates = {}
    if payload.category is not None and payload.category != "":
        updates["category"] = payload.category
    if payload.status is not None and payload.status != "":
        updates["status"] = payload.status

    if not updates:
        raise HTTPException(status_code=400, detail="No fields provided for bulk update")

    updated = db.query(Item).filter(Item.id.in_(payload.ids)).update(updates, synchronize_session=False)
    db.commit()
    return BulkActionResponse(updated=updated)
